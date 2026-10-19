<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainDefaults;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Mailstore\Acl;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\DottedNamespace;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Password\PersonalTerms;
use Mailadmin\Domain\Policy\AccountPolicy;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Infrastructure\Mailstore\ErrorCode as MailstoreErrorCode;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `POST /domains/{domain}/accounts`.
 *
 * ## The order (architecture 8.1)
 *
 * 1. **Mail store:** create the mailbox, give its owner the full ACL, set the
 *    quota.
 * 2. **Database:** `accountuser` and the address row in `virtual`, in one
 *    transaction with the audit entry, under the namespace lock and the
 *    per-domain lock.
 * 3. **Mail store again:** the domain's default folders, created and subscribed
 *    in the owner's name through proxy authorisation.
 *
 * Mailbox before row, because a mailbox without a row is invisible and harmless
 * and a row without a mailbox makes Postfix accept mail it cannot deliver.
 *
 * ## What happens when a step fails
 *
 * - **Before the mailbox exists** (validation, the courtesy checks, a `CREATE`
 *   Cyrus refused): nothing to undo. A mailbox that already exists is `409
 *   account.mailbox_exists` and is left alone — this request did not make it.
 * - **`CREATE` without a definite answer** (timeout, dropped connection,
 *   unreadable reply): the mailbox may or may not exist now, and if it does, it
 *   may be this request's or one that was there before. It is not removed —
 *   removing a mailbox that might hold somebody's mail on a guess is the worse
 *   mistake. A probe asks whether it is there; if it is, or the probe fails
 *   too, it is recorded as `mailstore.create_mailbox_uncertain`, so that the
 *   `409 account.mailbox_exists` a retry may meet has an explanation (U-86).
 * - **After the mailbox, before or in the database step:** the mailbox is
 *   deleted again (the compensation), a `failed` audit entry is written, and
 *   the answer is `409 account.setup_incomplete` — or the refusal the database
 *   step made (limit, name) when a concurrent request got there first. Only if
 *   the compensation **fails** is there a repair-queue entry: an orphan mailbox
 *   is what is left over, and what is left over belongs in the queue (U-85).
 *   Because the legacy tables may not roll back (O-11), rows a failed database
 *   step left behind are deleted first; if that fails, the mailbox is kept —
 *   a mailbox under a row is harmless, a row without one bounces mail.
 * - **In step 3:** the account works; folders are a convenience. It stays, the
 *   answer is `201` with `incomplete_steps`, and the queue gets an entry (U-80).
 *
 * ## The checks that run twice
 *
 * Name, account limit and the dotted-namespace check (U-86) run before step 1 so
 * that the ordinary refusal touches neither system, and again inside step 2,
 * because between the two another request may have taken the name or the last
 * place (U-81), created a subdomain, or deleted the domain. Step 2 therefore
 * runs under two locks: the namespace lock that domain creation, rename and
 * deletion take as well, and inside it {@see AccountStore::withDomainLock()}
 * (U-97).
 */
final readonly class CreateAccount
{
    public const string OPERATION = 'account.create';

    /** `virtual.username` is `VARCHAR(50)` and stays so (CLAUDE.md, rule one; U-85). */
    public const int MAX_CYRUS_USERNAME_LENGTH = 50;

    public function __construct(
        private AccountStore $store,
        private DomainStore $domains,
        private DomainDefaults $defaults,
        private Mailstore $mailstore,
        private DomainAllowance $allowance,
        private PasswordPolicy $passwords,
        private PasswordHasher $hasher,
        private RepairQueue $repairs,
        private AuditWriter $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Domain\Exception\DomainError when the password breaks a rule
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, DomainName $domainName, AccountDraft $draft): AccountCreated
    {
        AccountPolicy::requireCreateIn($actor, $domainName);

        $domain = ($this->domains->find($domainName) ?? throw AccountOperationFailed::notFound('such domain'))
            ->domain;

        $identity = MailboxIdentity::create(
            $draft->localPart,
            $domain->name,
            $this->defaults->resolve($domain->namingScheme),
        );

        $username = $identity->cyrusUsername();

        if (strlen($username) > self::MAX_CYRUS_USERNAME_LENGTH) {
            throw AccountOperationFailed::usernameTooLong($username, self::MAX_CYRUS_USERNAME_LENGTH);
        }

        // Before anything is touched: a password the rules refuse must not
        // leave a mailbox behind.
        $hash = $this->hasher->hash($this->passwords->accept(
            $draft->password,
            PasswordSubject::AccountUser,
            PersonalTerms::forMailbox($identity),
        ));

        $quota = $draft->quota ?? $domain->defaultAccountQuota;

        $this->requireUnambiguous($identity);
        $this->requireFree($domain, $identity);
        $this->allowance->requireRoomFor($domain, $identity, $quota);

        $record = new AccountRecord(
            $username,
            $domain->name,
            $identity,
            $draft->services,
            PasswordFormat::Bcrypt,
            $domain->legacyPrefix,
        );

        $this->createMailbox($identity);
        $this->prepareMailbox($actor, $identity, $quota);
        $this->writeRows($actor, $domain, $record, $hash);

        return new AccountCreated($record, $this->createFolders($actor, $domain, $identity));
    }

    /**
     * Name and account limit. Run before the mailbox step and again under the
     * lock.
     *
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requireFree(Domain $domain, MailboxIdentity $identity): void
    {
        if ($this->store->isTaken($identity)) {
            throw AccountOperationFailed::nameTaken($identity->address()->toString(), $identity->cyrusUsername());
        }

        $count = $this->store->countIn($domain->name);

        if (!$domain->permitsAnotherAccount($count)) {
            throw AccountOperationFailed::limitReached(
                $domain->name->toString(),
                $count,
                $domain->maxAccounts->maximum(),
            );
        }
    }

    /**
     * In the dotted scheme the Cyrus user name `info.kunde.example.de` is what
     * both `info.kunde@example.de` and `info@kunde.example.de` get
     * (architecture 3.3). If a domain `kunde.example.de` exists, the name
     * belongs to its namespace: a domain master of `example.de` could otherwise
     * claim logins in a subdomain someone else administers (U-86). Refused as
     * `name_taken`, which is what that domain's own account would meet.
     *
     * Run before the mailbox step and again under the namespace lock, which
     * domain creation and rename take as well (U-97); the reverse direction —
     * a subdomain created after the account — is refused there (U-96).
     *
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requireUnambiguous(MailboxIdentity $identity): void
    {
        foreach (DottedNamespace::subdomainsReachedBy($identity) as $subdomain) {
            if ($this->domains->exists($subdomain)) {
                throw AccountOperationFailed::nameTaken($identity->address()->toString(), $identity->cyrusUsername());
            }
        }

        // A user name that is a domain's name is that domain's alias
        // placeholder (U-106, U-110).
        $namesake = DottedNamespace::domainNamedLike($identity);

        if ($namesake !== null && $this->domains->exists($namesake)) {
            throw AccountOperationFailed::nameTaken($identity->address()->toString(), $identity->cyrusUsername());
        }
    }

    /**
     * @throws AccountOperationFailed
     */
    private function createMailbox(MailboxIdentity $identity): void
    {
        try {
            $this->mailstore->createMailbox($identity);
        } catch (MailstoreFailure $failure) {
            if (!self::isDefinite($failure) && $this->mayExist($identity)) {
                $this->recordUncertainMailbox($identity, $failure);
            }

            throw AccountOperationFailed::fromMailstore($failure, $identity->imapMailbox());
        }
    }

    /**
     * Did Cyrus answer the `CREATE`? Only then is it known that nothing was
     * created — `NO`/`BAD`, or the mailbox already being there. A timeout, a
     * dropped connection or a reply that did not parse may come after the
     * command took effect.
     */
    private static function isDefinite(MailstoreFailure $failure): bool
    {
        return in_array($failure->errorCode(), [
            MailstoreErrorCode::MAILBOX_EXISTS,
            MailstoreErrorCode::COMMAND_FAILED,
            MailstoreErrorCode::AUTHENTICATION_FAILED,
            MailstoreErrorCode::TLS_REJECTED,
            MailstoreErrorCode::CONFIGURATION_INVALID,
            MailstoreErrorCode::UNSUPPORTED_NAMESPACE,
        ], true);
    }

    /**
     * After a `CREATE` without an answer: is there a mailbox now? A probe that
     * fails as well counts as "maybe".
     */
    private function mayExist(MailboxIdentity $identity): bool
    {
        try {
            return $this->mailstore->mailboxExists($identity);
        } catch (MailstoreFailure) {
            return true;
        }
    }

    private function recordUncertainMailbox(MailboxIdentity $identity, MailstoreFailure $failure): void
    {
        $this->logger->error('Creating a mailbox ended without an answer; it may exist now.', [
            'account' => $identity->cyrusUsername(),
            'reason' => $failure->errorCode(),
        ]);

        try {
            $this->repairs->record(new RepairRequest(
                operation: self::OPERATION,
                step: 'mailstore.create_mailbox_uncertain',
                subject: $identity->imapMailbox(),
                intended: sprintf('create %s for a new account %s', $identity->imapMailbox(), $identity->cyrusUsername()),
                reached: 'unknown whether the mailbox is this request\'s; no account row claims it, and it was not removed',
                reason: $failure->errorCode(),
            ));
        } catch (Throwable $error) {
            // The answer stays the mail store's failure; the log line above is
            // then the only record.
            $this->logger->error('The repair entry for an uncertain mailbox could not be written.', [
                'account' => $identity->cyrusUsername(),
                'reason' => $error->getMessage(),
            ]);
        }
    }

    /**
     * ACL and quota. A failure here is a failure after the mailbox step.
     *
     * @throws AccountOperationFailed
     */
    private function prepareMailbox(ActorContext $actor, MailboxIdentity $identity, Quota $quota): void
    {
        try {
            $this->mailstore->setAcl($identity, $identity->cyrusUsername(), Acl::fullControl());

            if (!$quota->isUnlimited()) {
                $this->mailstore->setQuota($identity, $quota);
            }
        } catch (MailstoreFailure $failure) {
            throw $this->abandon($actor, $identity, 'mailstore: ' . $failure->errorCode());
        }
    }

    /**
     * @throws AccountOperationFailed
     */
    private function writeRows(ActorContext $actor, Domain $domain, AccountRecord $record, string $hash): void
    {
        $identity = $record->requireIdentity();

        try {
            // Namespace lock outside, domain lock inside — the one order every
            // caller uses, so the two cannot deadlock (U-97).
            $this->domains->withNamespaceLock(fn () => $this->store->withDomainLock(
                $domain->name,
                function () use ($actor, $domain, $record, $identity, $hash): void {
                    // The domain may have been deleted, or a subdomain created,
                    // since the courtesy checks. Both take the namespace lock.
                    if (!$this->domains->exists($domain->name)) {
                        throw AccountOperationFailed::notFound('such domain');
                    }

                    $this->requireUnambiguous($identity);
                    $this->requireFree($domain, $identity);

                    $this->store->atomically(function () use ($actor, $domain, $record, $identity, $hash): void {
                        $this->store->create($identity, $hash, $record->services, $record->legacyPrefix);

                        $this->audit->record(AuditEntry::creation(
                            $actor,
                            self::OPERATION,
                            'account',
                            $record->storedUsername,
                            after: AccountView::full($record),
                            domain: $domain->name,
                        ));
                    });
                },
            ));
        } catch (AccountOperationFailed $refusal) {
            // A concurrent request took the name or the last place, created a
            // subdomain whose namespace the name falls into, or deleted the
            // domain — between the courtesy check and the locks. The mailbox
            // this request made goes.
            $this->abandon($actor, $identity, 'database: ' . $refusal->errorCode);

            throw $refusal;
        } catch (Throwable $error) {
            $this->logger->error('The database half of an account creation failed.', [
                'admin' => $actor->username,
                'account' => $record->storedUsername,
                'reason' => $error->getMessage(),
            ]);

            // The legacy tables may be MyISAM (O-11), where the rollback undoes
            // nothing: rows this request wrote can still be there. Removing the
            // mailbox under them would leave Postfix accepting mail it cannot
            // deliver, so the rows go first, and the mailbox only if they did.
            if (!$this->removeLeftoverRows($record->storedUsername, $identity)) {
                throw $this->abandon($actor, $identity, 'database: write failed', removeMailbox: false);
            }

            throw $this->abandon($actor, $identity, 'database: write failed');
        }
    }

    /**
     * After a failed database step: delete whatever rows of this account are
     * left. Under the lock the name was free, so any row under it is this
     * request's. On InnoDB there is nothing to delete and this is a no-op.
     *
     * @return bool whether the rows are now known to be gone
     */
    private function removeLeftoverRows(string $storedUsername, MailboxIdentity $identity): bool
    {
        try {
            $this->store->delete($storedUsername, $identity->domain);

            return true;
        } catch (Throwable $error) {
            $this->logger->error('Rows of an abandoned account creation could not be removed.', [
                'account' => $storedUsername,
                'reason' => $error->getMessage(),
            ]);

            $this->repairs->record(new RepairRequest(
                operation: self::OPERATION,
                step: 'database.remove_rows',
                subject: $storedUsername,
                intended: sprintf('no rows for %s after its creation was abandoned', $storedUsername),
                reached: sprintf(
                    'rows for %s may exist; the mailbox %s was kept so that they do not point at nothing',
                    $storedUsername,
                    $identity->imapMailbox(),
                ),
                reason: 'the database refused the cleanup',
            ));

            return false;
        }
    }

    /**
     * Step 3. Never fails the request.
     *
     * @return list<string> the steps that did not complete
     */
    private function createFolders(ActorContext $actor, Domain $domain, MailboxIdentity $identity): array
    {
        if ($domain->defaultFolders->isEmpty()) {
            return [];
        }

        try {
            $this->mailstore->createDefaultFolders($identity, $domain->defaultFolders);
        } catch (MailstoreFailure $failure) {
            $this->logger->warning('The default folders of a new account could not be created.', [
                'admin' => $actor->username,
                'account' => $identity->cyrusUsername(),
                'reason' => $failure->errorCode(),
            ]);

            $this->repairs->record(new RepairRequest(
                operation: self::OPERATION,
                step: 'mailstore.default_folders',
                subject: $identity->imapMailbox(),
                intended: sprintf(
                    'create and subscribe %s in %s',
                    implode(', ', $domain->defaultFolders->names()),
                    $identity->imapMailbox(),
                ),
                reached: 'the account exists and works; some or all of its default folders do not',
                reason: $failure->errorCode(),
                parameters: [
                    'account' => $identity->cyrusUsername(),
                    'domain' => $identity->domain->toString(),
                    'folders' => $domain->defaultFolders->toLegacyString(),
                ],
            ));

            return [AccountCreated::STEP_DEFAULT_FOLDERS];
        }

        return [];
    }

    /**
     * The compensation: remove the mailbox this request created, record what is
     * left, write the `failed` audit entry (U-76) and say what happened.
     */
    private function abandon(
        ActorContext $actor,
        MailboxIdentity $identity,
        string $stage,
        bool $removeMailbox = true,
    ): AccountOperationFailed {
        $compensated = $removeMailbox;

        try {
            if ($removeMailbox) {
                $this->mailstore->deleteMailbox($identity);
            }
        } catch (MailstoreFailure $failure) {
            $compensated = false;

            $this->repairs->record(new RepairRequest(
                operation: self::OPERATION,
                step: 'mailstore.delete_mailbox',
                subject: $identity->imapMailbox(),
                intended: sprintf(
                    'remove %s again after creating account %s stopped at %s',
                    $identity->imapMailbox(),
                    $identity->cyrusUsername(),
                    $stage,
                ),
                reached: 'the mailbox exists and no account row claims it',
                reason: $failure->errorCode(),
                parameters: [
                    'account' => $identity->cyrusUsername(),
                    'domain' => $identity->domain->toString(),
                ],
            ));
        }

        $this->logger->error('An account creation was abandoned after its mailbox was created.', [
            'admin' => $actor->username,
            'account' => $identity->cyrusUsername(),
            'stage' => $stage,
            'mailbox_removed' => $compensated,
        ]);

        try {
            $this->audit->record(AuditEntry::failure(
                $actor,
                self::OPERATION,
                'account',
                $identity->cyrusUsername(),
                reason: sprintf(
                    'stopped at %s; %s',
                    $stage,
                    $compensated ? 'mailbox removed' : 'mailbox not removed, see repair queue',
                ),
                domain: $identity->domain,
            ));
        } catch (Throwable $error) {
            $this->logger->error('The audit entry for a failed account creation could not be written.', [
                'admin' => $actor->username,
                'account' => $identity->cyrusUsername(),
                'reason' => $error->getMessage(),
            ]);
        }

        return AccountOperationFailed::setupIncomplete($identity->cyrusUsername(), $stage, $compensated);
    }
}

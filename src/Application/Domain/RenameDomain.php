<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\DottedNamespace;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Policy\DomainPolicy;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renaming a domain — the most expensive operation in the application, and the
 * one the legacy system got wrong.
 *
 * The domain name is part of every Cyrus user name (architecture 3.3), so
 * changing the `domain` row alone leaves every account of the domain
 * unreachable: that is what web-cyradm did. The order here is therefore the one
 * architecture 8.1 prescribes, and every step of it exists because of a way the
 * operation can fail.
 *
 * ```text
 * 1. derive every account's new identity        — before anything is touched
 * 2. probe the mail store                       — refuse if it cannot be reached
 * 3. renamemailbox, account by account          — undo everything on a failure
 * 4. under the namespace lock: the checks again,
 *    and the plan against the accounts now,
 *    then one transaction over domain,
 *    accountuser, virtual, domainadmin and the
 *    settings row                               — undo the mailboxes if it fails
 * ```
 *
 * ## Why step 1 can refuse
 *
 * The new user name is derived from the stored one through
 * {@see MailboxIdentity::fromStoredUsername()}, which needs the account's
 * `domain_name` to know where the local part ends. A row whose user name
 * contradicts its domain has no derivable new name — renaming the domain would
 * strand exactly that account, which is the failure this use case exists to
 * prevent. So it refuses before touching anything, and says which account.
 *
 * ## Why step 1 can also refuse for the namespace (U-96)
 *
 * In the dotted scheme the new name must not make a Cyrus user name ambiguous —
 * neither for accounts that already exist under a parent of the new name nor
 * for the accounts this rename moves. {@see requireUnambiguous()} says how. The
 * check runs before anything moves and again in step 4 under the namespace
 * lock that account and domain creation take too (U-97). A refusal there comes
 * after the mailboxes moved, so it takes the same way back as any other failure
 * of step 4 and then answers with its own code.
 *
 * ## Why step 4 compares the plan with the accounts (U-100)
 *
 * Account creation holds the namespace lock only for its short database step,
 * not while the mailboxes here move. An account committed in between is not in
 * the plan: the transaction would move the domain without its user name, which
 * strands exactly that account. So the stored names are read again under the
 * lock, and a difference takes the way back like any refusal there. The same
 * re-read sees a domain that was deleted meanwhile — possible only for an empty
 * one, which has no mailbox step — and answers `404` instead of reporting a
 * rename of nothing as a success.
 *
 * ## Why the target must not carry leftovers (U-99, U-101)
 *
 * Assignments and `virtual` rows of an earlier domain named `$to` would apply
 * to the renamed one ({@see DomainLeftovers}), and a forward keyed by a moved
 * account's new user name would apply to that account. Both refuse, before
 * anything moves and again under the lock. The account's own forwards move with
 * it ({@see DomainStore::rename()}).
 *
 * ## Why step 2 is a probe and not optimism
 *
 * A1's open point 8 asked whether an unreachable mail store should refuse the
 * rename. It should, and this is where: the alternative is discovering it after
 * the first `renamemailbox`, which is a state to unwind rather than a state to
 * avoid. A domain with no accounts skips the probe — there is nothing to rename
 * and no reason to require a mail server to be up.
 *
 * ## What "undo" means, and what it cannot mean
 *
 * Compensation renames the mailboxes back, in reverse order. When it succeeds,
 * nothing changed and mail keeps arriving under the old name — the safe failure.
 * When it does not, the mailbox is under a name no database row mentions, and
 * that goes into the repair queue with what was intended and what was reached
 * (architecture 8.2). It is never only logged, and it is never left silent.
 *
 * ## What the audit trail says (U-76)
 *
 * **Success:** one `domain.rename` entry for the domain, with the representation
 * before and after, and one child entry per account — same action, object type
 * `account`, the old and the new Cyrus user name — pointing at it through
 * `part_of` (U-61). All of them are written in the transaction of step 4, so
 * they commit with the five tables or not at all (U-63). A failing audit write
 * therefore rolls the database half back and sends the mailboxes back too, which
 * is the same path as any other failure of step 4.
 *
 * **A rename that stopped part way:** one `domain.rename` entry with the outcome
 * `failed`, written **after** the compensation, when it is known how far the
 * undo got. `failed` and not something in between, because the entry records
 * what the database says (U-63), and the database still says the old name. The
 * reason names the stage and whether the mailboxes came back; a mailbox that did
 * not is in the repair queue, and the entry points there rather than repeating
 * it. No child entries: nothing changed for any account.
 *
 * The failure entry is the one write here whose own failure is caught. By then
 * the operation has already failed, nothing is left to roll back, and letting a
 * broken audit table replace `domain.rename_incomplete` — the answer that says
 * whether the mailboxes came back — with a bare `500` would hide exactly what the
 * operator needs. It is logged at `error` instead.
 *
 * Refusals before anything moved — an account whose name cannot be derived, an
 * unreachable mail store — are not audited, like A4's `409`s: nothing was
 * attempted, and the application log has them.
 */
final readonly class RenameDomain
{
    /** The stable operation key repair entries and audit entries carry. */
    public const string OPERATION = 'domain.rename';

    public function __construct(
        private DomainStore $store,
        private Mailstore $mailstore,
        private RepairQueue $repairs,
        private DomainDefaults $defaults,
        private AuditWriter $audit,
        private LoggerInterface $logger,
        private ListDelivery $delivery,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws DomainOperationFailed
     * @throws InvalidAddress when the target is the list transport domain
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, DomainName $from, DomainName $to): void
    {
        DomainPolicy::requireWrite($actor, $from);

        // Before any mailbox moves: as a managed domain, the transport domain
        // would stop every list (see CreateDomain).
        if ($this->delivery->isTransportDomain($to)) {
            throw InvalidAddress::transportDomainName($to->toString());
        }

        if ($from->toString() === $to->toString()) {
            return;
        }

        $current = $this->store->find($from) ?? throw DomainOperationFailed::notFound($from);
        $before = DomainView::full($current, $this->defaults);

        $plan = $this->plan($from, $to);

        // The courtesy round, before anything moves.
        $this->requireNoLeftovers($from, $to);
        $this->requireUnambiguous($from, $to, $plan);

        if ($plan !== []) {
            $this->requireReachableMailstore($from, $plan[0]);
        }

        $done = $this->renameMailboxes($actor, $from, $to, $plan, $before);

        try {
            $this->store->withNamespaceLock(function () use ($actor, $from, $to, $plan, $before): void {
                // Again under the lock (U-97): an account or a domain that
                // claimed the name while the mailboxes moved is seen here.
                if (!$this->store->exists($from)) {
                    throw DomainOperationFailed::notFound($from);
                }

                if (!$from->equals($to) && $this->store->exists($to)) {
                    throw DomainOperationFailed::nameTaken($to);
                }

                $this->requirePlanCoversEveryAccount($from, $plan);
                $this->requireNoLeftovers($from, $to);
                $this->requireUnambiguous($from, $to, $plan);

                // Outside the transaction, so that it stands whatever the
                // MyISAM half of the rename leaves behind (U-129).
                $this->store->carryAccountStatus(self::nameMap($plan));

                $this->store->atomically(function () use ($actor, $from, $to, $plan, $before): void {
                    $this->store->rename($from, $to, self::nameMap($plan));

                    // Inside the transaction (U-63): the entries commit with the
                    // five tables, and a failing write here takes the database
                    // half back with it — and, through the catch below, the
                    // mailboxes.
                    $this->recordSuccess($actor, $from, $to, $plan, $before);
                });
            });
        } catch (DomainOperationFailed $refusal) {
            // Somebody took the name, or its namespace, between the courtesy
            // check and the lock. Nothing in the database changed; the
            // mailboxes go back, and the refusal is the answer.
            $compensated = $this->undo($from, $to, $done);

            $this->logger->notice('A domain rename was refused under the namespace lock.', [
                'admin' => $actor->username,
                'domain' => $from->toString(),
                'target' => $to->toString(),
                'reason' => $refusal->errorCode,
                'mailboxes_restored' => $compensated,
            ]);

            $this->recordFailure($actor, $from, $to, 'database: ' . $refusal->errorCode, $compensated, $before);

            if (!$compensated) {
                throw DomainOperationFailed::renameIncomplete($from, $refusal->getMessage(), false);
            }

            throw $refusal;
        } catch (Throwable $error) {
            // The mailboxes moved and the database did not — the one state that
            // must not survive this method. Put them back.
            $compensated = $this->undo($from, $to, $done);

            $this->logger->error('The database half of a domain rename failed.', [
                'admin' => $actor->username,
                'domain' => $from->toString(),
                'target' => $to->toString(),
                'reason' => $error->getMessage(),
                'mailboxes_restored' => $compensated,
            ]);

            $this->recordFailure($actor, $from, $to, 'database: write failed', $compensated, $before);

            // The database's own message stays in the log: it is not the
            // client's to read (CLAUDE.md, "Fehler").
            throw DomainOperationFailed::renameIncomplete($from, 'The database update failed.', $compensated);
        }
    }

    /**
     * The new name must not make a Cyrus user name ambiguous (U-96).
     *
     * Three ways it could, all in the dotted scheme:
     *
     *  1. an account of a **parent** of `$to` already ends in `.<to>` — the
     *     same check as creating `$to` ({@see CreateDomain});
     *  2. a moved account's local part has dots, and one of its tails together
     *     with `$to` is an existing domain — U-86 for the account;
     *  3. a moved account's new user name is already somebody's — an account,
     *     the key of a forward row in `virtual` (U-101), the sender a row of
     *     `virtual` names, or the name of a domain (U-110).
     *
     * The domain's own accounts are left out of 1 and 3: they move along. A
     * change of spelling only changes nothing about the namespace.
     *
     * @param list<RenameStep> $plan
     *
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requireUnambiguous(DomainName $from, DomainName $to, array $plan): void
    {
        if ($from->equals($to)) {
            return;
        }

        $claimant = $this->store->namespaceClaimant($to, except: $from);

        if ($claimant !== null) {
            throw DomainOperationFailed::namespaceConflict($to, $claimant);
        }

        foreach ($plan as $step) {
            foreach (DottedNamespace::subdomainsReachedBy($step->to) as $subdomain) {
                if (!$subdomain->equals($from) && $this->store->exists($subdomain)) {
                    throw DomainOperationFailed::namespaceConflict($to, $step->to->cyrusUsername());
                }
            }

            if ($this->store->accountUsernameTaken($step->to->cyrusUsername(), $from)) {
                throw DomainOperationFailed::namespaceConflict($to, $step->to->cyrusUsername());
            }

            // A forward left over under the new name would apply to the moved
            // account (U-101) — the rule AccountStore::isTaken() applies to a
            // new one.
            if ($this->store->aliasTaken($step->to->cyrusUsername())) {
                throw DomainOperationFailed::namespaceConflict($to, $step->to->cyrusUsername());
            }

            // A row naming the new name as its sender, or a domain whose alias
            // placeholder the new name is, would hand the moved account a
            // sending right it never had (U-110).
            if ($this->store->senderNameTaken($step->to->cyrusUsername())) {
                throw DomainOperationFailed::namespaceConflict($to, $step->to->cyrusUsername());
            }

            $namesake = DottedNamespace::domainNamedLike($step->to);

            if ($namesake !== null && !$namesake->equals($from) && $this->store->exists($namesake)) {
                throw DomainOperationFailed::namespaceConflict($to, $step->to->cyrusUsername());
            }
        }
    }

    /**
     * No assignments and no addresses of an earlier domain named `$to` (U-99).
     * A change of spelling only finds the domain's own rows, which move along.
     *
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requireNoLeftovers(DomainName $from, DomainName $to): void
    {
        if ($from->equals($to)) {
            return;
        }

        DomainLeftovers::requireNone($this->store, $to);
    }

    /**
     * The accounts of `$from` are still exactly the ones the plan moved
     * (U-100). Compared as stored, byte for byte, in any order.
     *
     * @param list<RenameStep> $plan
     *
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requirePlanCoversEveryAccount(DomainName $from, array $plan): void
    {
        $planned = array_map(static fn (RenameStep $step): string => $step->storedUsername, $plan);
        $current = $this->store->accountUsernames($from);

        sort($planned, SORT_STRING);
        sort($current, SORT_STRING);

        if ($planned !== $current) {
            throw DomainOperationFailed::accountsChangedDuringRename($from);
        }
    }

    /**
     * Every account's old and new identity, derived before anything moves.
     *
     * @return list<RenameStep>
     *
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function plan(DomainName $from, DomainName $to): array
    {
        $plan = [];

        foreach ($this->store->accountUsernames($from) as $stored) {
            try {
                $identity = MailboxIdentity::fromStoredUsername($stored, $from);
            } catch (DomainError $error) {
                throw DomainOperationFailed::renameBlockedByAccount($from, $stored, $error->code());
            }

            $plan[] = new RenameStep($stored, $identity, $identity->withDomain($to));
        }

        return $plan;
    }

    /**
     * @throws DomainOperationFailed
     */
    private function requireReachableMailstore(DomainName $from, RenameStep $probe): void
    {
        try {
            $this->mailstore->mailboxExists($probe->from);
        } catch (MailstoreFailure $failure) {
            throw DomainOperationFailed::mailstoreUnavailable($from, $failure->errorCode());
        }
    }

    /**
     * @param list<RenameStep>     $plan
     * @param array<string, mixed> $before
     *
     * @return list<RenameStep> the steps that were carried out
     *
     * @throws DomainOperationFailed
     */
    private function renameMailboxes(
        ActorContext $actor,
        DomainName $from,
        DomainName $to,
        array $plan,
        array $before,
    ): array {
        $done = [];

        foreach ($plan as $step) {
            try {
                $this->mailstore->renameMailbox($step->from, $step->to);
            } catch (MailstoreFailure $failure) {
                $compensated = $this->undo($from, $to, $done);

                $this->logger->error('A domain rename stopped part way through the mail store.', [
                    'admin' => $actor->username,
                    'domain' => $from->toString(),
                    'target' => $to->toString(),
                    'failed_mailbox' => $step->from->imapMailbox(),
                    'reason' => $failure->errorCode(),
                    'mailboxes_restored' => $compensated,
                ]);

                $this->recordFailure(
                    $actor,
                    $from,
                    $to,
                    'mailstore: ' . $failure->errorCode(),
                    $compensated,
                    $before,
                );

                throw DomainOperationFailed::renameIncomplete($from, $failure->errorCode(), $compensated);
            }

            $done[] = $step;
        }

        return $done;
    }

    /**
     * Rename back, newest first. Returns whether everything came back.
     *
     * @param list<RenameStep> $done
     */
    private function undo(DomainName $from, DomainName $to, array $done): bool
    {
        $complete = true;

        foreach (array_reverse($done) as $step) {
            try {
                $this->mailstore->renameMailbox($step->to, $step->from);
            } catch (MailstoreFailure $failure) {
                $complete = false;

                $this->repairs->record(new RepairRequest(
                    operation: self::OPERATION,
                    step: 'mailstore.rename_back',
                    subject: $step->to->imapMailbox(),
                    intended: sprintf(
                        'rename %s back to %s after renaming %s to %s failed',
                        $step->to->imapMailbox(),
                        $step->from->imapMailbox(),
                        $from->toString(),
                        $to->toString(),
                    ),
                    reached: sprintf(
                        'the mailbox is still %s while the database still says %s',
                        $step->to->imapMailbox(),
                        $from->toString(),
                    ),
                    reason: $failure->errorCode(),
                    // `account`/`domain` is what the database still holds; the
                    // mailbox sits under `target_*`.
                    parameters: [
                        'account' => $step->storedUsername,
                        'domain' => $step->from->domain->toString(),
                        'target_account' => $step->to->cyrusUsername(),
                        'target_domain' => $step->to->domain->toString(),
                    ],
                ));
            }
        }

        return $complete;
    }

    /**
     * The operation entry and one child per account, inside the transaction.
     *
     * @param list<RenameStep>     $plan
     * @param array<string, mixed> $before
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function recordSuccess(
        ActorContext $actor,
        DomainName $from,
        DomainName $to,
        array $plan,
        array $before,
    ): void {
        $renamed = $this->store->find($to);

        $operation = $this->audit->record(AuditEntry::change(
            $actor,
            self::OPERATION,
            'domain',
            $from->toString(),
            before: $before,
            after: $renamed === null ? ['name' => $to->toString()] : DomainView::full($renamed, $this->defaults),
            domain: $to,
        ));

        foreach ($plan as $step) {
            $this->audit->record(AuditEntry::change(
                $actor,
                self::OPERATION,
                'account',
                $step->storedUsername,
                before: ['username' => $step->storedUsername],
                after: ['username' => $step->to->cyrusUsername()],
                domain: $to,
            )->partOf($operation));
        }
    }

    /**
     * The `failed` entry, after the compensation (U-63, U-76).
     *
     * @param array<string, mixed> $before
     */
    private function recordFailure(
        ActorContext $actor,
        DomainName $from,
        DomainName $to,
        string $stage,
        bool $compensated,
        array $before,
    ): void {
        try {
            $this->audit->record(AuditEntry::failure(
                $actor,
                self::OPERATION,
                'domain',
                $from->toString(),
                reason: sprintf(
                    'rename to %s stopped at %s; %s',
                    $to->toString(),
                    $stage,
                    $compensated ? 'mailboxes restored' : 'mailboxes not restored, see repair queue',
                ),
                before: $before,
                domain: $from,
            ));
        } catch (Throwable $error) {
            $this->logger->error('The audit entry for a failed domain rename could not be written.', [
                'admin' => $actor->username,
                'domain' => $from->toString(),
                'target' => $to->toString(),
                'reason' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @param list<RenameStep> $plan
     *
     * @return array<string, string>
     */
    private static function nameMap(array $plan): array
    {
        $map = [];

        foreach ($plan as $step) {
            $map[$step->storedUsername] = $step->to->cyrusUsername();
        }

        return $map;
    }
}

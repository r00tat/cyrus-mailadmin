<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Application\Account\AccountStore;
use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Runtime\Clock;
use Psr\Log\LoggerInterface;

/**
 * `POST /maintenance/repairs/{repair}/retry`: finish the step an operation
 * could not (A6 open point 5, U-118).
 *
 * ## Check first, then act — and only on what the entry names
 *
 * An entry describes the state at the moment something failed. By the time
 * somebody retries it, an hour or a month later, the state may have moved on:
 * the account was created again under the same name, the quota was changed by
 * hand, the domain rename was repeated and succeeded. Retrying blindly would act
 * on a state the entry knows nothing about — and for a mailbox deletion that is
 * a mailbox with somebody's mail in it.
 *
 * So every retry first checks that the state is still the one the entry left
 * behind, and refuses with `repair.state_changed` if it is not. Nothing is done
 * then; the administrator looks, and dismisses the entry if it no longer
 * matters. The deletion and the rename check under the locks account and domain
 * operations take (U-97), so that a creation cannot slip in between check and
 * act.
 *
 * The entry itself is read and closed under its own lock
 * ({@see RepairLedger::withEntryLock()}), which a dismissal takes as well: two
 * retries, or a retry and a dismissal, never both act on one entry.
 *
 * A step that finds the state already where the entry wants it — the quota
 * restored, the mailbox back under its old name — counts as done and only
 * closes the entry. That is what an earlier retry leaves behind when its step
 * went through but closing the entry failed.
 *
 * ## Identities come from the stored name and its domain
 *
 * The entry carries the stored Cyrus user name and its `domain_name`, and the
 * identity is rebuilt with {@see MailboxIdentity::fromStoredUsername()} — the
 * one recognition path CLAUDE.md allows. Nothing here takes a user name apart.
 *
 * ## What a failure does
 *
 * A mail-store refusal leaves the entry open, counts the attempt with its code,
 * and writes a `failed` audit entry. A success closes it as `retried` and writes
 * a `succeeded` one.
 */
final readonly class RetryRepair
{
    public const string ACTION = 'repair.retry';

    public function __construct(
        private RepairLedger $ledger,
        private Mailstore $mailstore,
        private AccountStore $accounts,
        private DomainStore $domains,
        private AuditWriter $audit,
        private Clock $clock,
        private LoggerInterface $logger,
        private MailingListRepository $lists,
        private AddressStore $virtual,
    ) {
    }

    /**
     * @throws RepairOperationFailed
     */
    public function handle(ActorContext $actor, int $id): RepairEntry
    {
        $this->retryableOrRefuse($this->ledger->find($id), $id);

        // Read again under the entry's lock: between the read above and this
        // point another retry may have finished it, or an administrator may
        // have dismissed it — "keep the mailbox" — and acting on the first
        // read would undo that decision.
        return $this->ledger->withEntryLock($id, function () use ($actor, $id): RepairEntry {
            $entry = $this->retryableOrRefuse($this->ledger->find($id), $id);

            try {
                $this->perform($entry);
            } catch (RepairOperationFailed $refusal) {
                $this->recordFailure($actor, $entry, $refusal->lastError(), $refusal->getMessage());

                throw $refusal;
            } catch (MailstoreFailure $failure) {
                $this->recordFailure($actor, $entry, $failure->errorCode(), 'the mail store refused the retry');

                throw RepairOperationFailed::retryFailed($id, $failure->errorCode(), $failure->isRetryable());
            }

            if (!$this->ledger->resolve($id, RepairResolution::Retried, $actor->username, '', $this->clock->now())) {
                // Only a change by hand can get here while the lock is held.
                // The step ran, but the entry is somebody else's to report.
                $this->logger->warning('A repair entry was closed by somebody else while it was retried.', [
                    'repair' => $id,
                    'step' => $entry->step,
                ]);

                throw RepairOperationFailed::alreadyResolved($id);
            }

            $after = $this->ledger->find($id) ?? $entry;

            $this->audit->record(AuditEntry::change(
                $actor,
                self::ACTION,
                'repair',
                (string) $id,
                $entry->auditState(),
                $after->auditState(),
                $entry->domain(),
            ));

            return $after;
        });
    }

    /**
     * @throws RepairOperationFailed
     */
    private function retryableOrRefuse(?RepairEntry $entry, int $id): RepairEntry
    {
        if ($entry === null) {
            throw RepairOperationFailed::notFound($id);
        }

        if (!$entry->isOpen()) {
            throw RepairOperationFailed::alreadyResolved($id);
        }

        if (!$entry->isRetryable()) {
            throw RepairOperationFailed::notRetryable(
                $id,
                sprintf('step %s is handled by hand', $entry->step),
            );
        }

        return $entry;
    }

    /**
     * @throws RepairOperationFailed
     * @throws MailstoreFailure
     */
    private function perform(RepairEntry $entry): void
    {
        match ($entry->step) {
            RepairCatalogue::DEFAULT_FOLDERS => $this->createFolders($entry),
            RepairCatalogue::DELETE_MAILBOX => $this->deleteMailbox($entry),
            RepairCatalogue::RESTORE_QUOTA => $this->restoreQuota($entry),
            RepairCatalogue::RENAME_BACK => $this->renameBack($entry),
            RepairCatalogue::REMOVE_LIST_ROW => $this->removeListRow($entry),
            default => throw RepairOperationFailed::notRetryable($entry->id, 'unknown step'),
        };
    }

    private function createFolders(RepairEntry $entry): void
    {
        $identity = $this->identity($entry, 'account', 'domain');

        if ($this->accounts->find($entry->parameter('account')) === null) {
            throw RepairOperationFailed::stateChanged('The account no longer exists.');
        }

        if (!$this->mailstore->mailboxExists($identity)) {
            throw RepairOperationFailed::stateChanged('The account has no mailbox.');
        }

        $folders = $this->folders($entry);

        if (!$folders->isEmpty()) {
            $this->mailstore->createDefaultFolders($identity, $folders);
        }
    }

    private function deleteMailbox(RepairEntry $entry): void
    {
        $identity = $this->identity($entry, 'account', 'domain');

        $this->domains->withNamespaceLock(fn () => $this->accounts->withDomainLock(
            $identity->domain,
            function () use ($identity): void {
                if ($this->accounts->isTaken($identity)) {
                    throw RepairOperationFailed::stateChanged(
                        'The user name is claimed again; the mailbox may belong to that account now.',
                    );
                }

                $this->mailstore->deleteMailbox($identity);
            },
        ));
    }

    private function restoreQuota(RepairEntry $entry): void
    {
        $identity = $this->identity($entry, 'account', 'domain');

        if ($this->accounts->find($entry->parameter('account')) === null) {
            throw RepairOperationFailed::stateChanged('The account no longer exists.');
        }

        $current = $this->mailstore->getQuota($identity)->limitKibibytes();
        $expected = self::kibibytes($entry, 'current_kib');
        $limit = self::kibibytes($entry, 'limit_kib');

        if ($current === $limit) {
            // Already the limit the entry wants back — typically an earlier
            // retry whose step went through but whose closing did not.
            return;
        }

        if ($current !== $expected) {
            throw RepairOperationFailed::stateChanged('The limit was changed since the entry was recorded.');
        }

        $this->mailstore->setQuota($identity, $limit === null ? Quota::unlimited() : Quota::ofKibibytes($limit));
    }

    private function renameBack(RepairEntry $entry): void
    {
        $original = $this->identity($entry, 'account', 'domain');
        $moved = $this->identity($entry, 'target_account', 'target_domain');

        $this->domains->withNamespaceLock(function () use ($entry, $original, $moved): void {
            $oldNameAlone = $this->accounts->find($entry->parameter('account')) !== null
                && $this->accounts->find($entry->parameter('target_account')) === null;

            if ($oldNameAlone
                && $this->mailstore->mailboxExists($original)
                && !$this->mailstore->mailboxExists($moved)
            ) {
                // Already back where the database expects it — an earlier
                // retry whose rename went through but whose closing did not.
                return;
            }

            if (!$oldNameAlone) {
                throw RepairOperationFailed::stateChanged(
                    'The database no longer holds the old name alone; the rename may have been repeated.',
                );
            }

            if (!$this->mailstore->mailboxExists($moved) || $this->mailstore->mailboxExists($original)) {
                throw RepairOperationFailed::stateChanged(
                    'The mailbox is no longer only under the new name.',
                );
            }

            $this->mailstore->renameMailbox($moved, $original);
        });
    }

    /**
     * A `lists` row whose `virtual` row is gone. Under the namespace lock,
     * which list creation takes as well, so a list created again for the
     * address in the meantime cannot lose its row between check and act. A
     * row that is already gone counts as done.
     */
    private function removeListRow(RepairEntry $entry): void
    {
        try {
            $address = EmailAddress::parseStored($entry->parameter('address'));
        } catch (InvalidAddress) {
            throw RepairOperationFailed::notRetryable($entry->id, 'the entry names no usable list address');
        }

        $this->domains->withNamespaceLock(function () use ($address): void {
            if ($this->virtual->rowsWithAlias($address->toString()) !== []) {
                throw RepairOperationFailed::stateChanged(
                    'The address has a virtual row again; the list row may be in use.',
                );
            }

            $this->lists->remove($address);
        });
    }

    private function identity(RepairEntry $entry, string $account, string $domain): MailboxIdentity
    {
        try {
            return MailboxIdentity::fromStoredUsername(
                $entry->parameter($account),
                DomainName::fromStoredValue($entry->parameter($domain)),
            );
        } catch (DomainError) {
            throw RepairOperationFailed::notRetryable($entry->id, 'the entry names no usable account');
        }
    }

    private function folders(RepairEntry $entry): DefaultFolders
    {
        try {
            return DefaultFolders::fromLegacyString($entry->parameter('folders'));
        } catch (DomainError) {
            throw RepairOperationFailed::notRetryable($entry->id, 'the entry names no usable folders');
        }
    }

    private static function kibibytes(RepairEntry $entry, string $name): ?int
    {
        $value = $entry->parameter($name);

        if ($value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            throw RepairOperationFailed::notRetryable($entry->id, sprintf('%s is not a number', $name));
        }

        return (int) $value;
    }

    private function recordFailure(ActorContext $actor, RepairEntry $entry, string $code, string $reason): void
    {
        $this->logger->warning('Retrying a repair entry failed.', [
            'repair' => $entry->id,
            'step' => $entry->step,
            'code' => $code,
            'reason' => $reason,
        ]);

        $this->ledger->recordAttempt($entry->id, $code, $this->clock->now());

        $this->audit->record(AuditEntry::failure(
            $actor,
            self::ACTION,
            'repair',
            (string) $entry->id,
            $reason,
            $entry->auditState(),
            $entry->domain(),
        ));
    }
}

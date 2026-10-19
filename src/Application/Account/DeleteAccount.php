<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\Address\CatchallCleanup;
use Mailadmin\Application\Address\CatchallCleanupPlan;
use Mailadmin\Application\Address\CatchallState;
use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `DELETE /accounts/{account}`.
 *
 * ## The order (architecture 8.1)
 *
 * 1. **Database:** the account's rows in `virtual` and `accountuser`, its
 *    status row (U-127) and the catchall rows that delivered to it (U-128), in
 *    one transaction with the audit entry. From here on Postfix accepts no more
 *    mail for the address.
 * 2. **Mail store:** the mailbox.
 *
 * ## The catchall goes with the account (U-128)
 *
 * A catchall `@<domain>` that delivers to the account would deliver into a
 * mailbox that no longer exists — every unknown address of the domain would
 * bounce. {@see CatchallCleanup} works out which destinations die with the
 * account; the database step writes the replacement rows **first**, removes
 * the old ones, then deletes the account, so that on MyISAM there is no moment
 * without a catchall that still had somewhere to deliver. A row left without
 * destinations is removed. The audit trail gets a `catchall.set` or
 * `catchall.delete` entry as a child of `account.delete`.
 *
 * The step runs under the namespace lock A3's catchall writes take, so the
 * rows cannot change between being read and being replaced — except by
 * webcyradm or a hand-made statement, which the count check before the write
 * catches (`409`, nothing changed). If the step fails and the account is
 * still there, the catchall rows are set back the way A3 sets back its own
 * writes ({@see RowWriter::restore()}).
 *
 * If step 2 fails, the account is gone all the same and the answer is still a
 * success: the mailbox left behind receives nothing, and it is a repair-queue
 * entry and an `error` log line rather than a refusal the client cannot act on.
 * Reversing the order would mean a moment in which Postfix delivers into a
 * mailbox that no longer exists.
 *
 * ## When the audit entry fails after the rows are gone (U-86)
 *
 * The legacy tables are MyISAM (O-11): a failing audit insert rolls the
 * `DELETE`s back on InnoDB and on MyISAM does not. So after a failed step 1 the
 * use case asks the database whether the account is still there:
 *
 * - **Still there** (InnoDB rolled back, or the delete itself failed before
 *   `accountuser`): nothing stands, the error goes to the client, the mailbox is
 *   not touched — a retry finishes the job.
 * - **Gone:** the deletion stands, and a refusal would make the client believe
 *   the account still exists (U-83). Step 2 runs as usual, and the missing audit
 *   entry is a repair-queue entry `audit.record`, so that the unrecorded
 *   deletion is visible somewhere other than a log line.
 *
 * ## What is not offered (U-83)
 *
 * No soft delete and no undelete. Whether Cyrus keeps a deleted mailbox for a
 * while (`delete_mode: delayed`) is server configuration this application does
 * not read; restoring one is an operator's job on the server. What the client
 * can do is show the usage from the quota resource in its confirmation — which
 * is why `If-Match` is required here: the confirmation the operator gave was for
 * the account they saw.
 */
final readonly class DeleteAccount
{
    public const string OPERATION = 'account.delete';

    public function __construct(
        private AccountStore $store,
        private AccountLocator $locator,
        private Mailstore $mailstore,
        private RepairQueue $repairs,
        private AuditWriter $audit,
        private LoggerInterface $logger,
        private DomainStore $domains,
        private AddressStore $addresses,
        private CatchallCleanup $catchall,
        private RowWriter $rows,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, string $id, ?string $ifMatch): void
    {
        $current = $this->locator->forDelete($actor, $id);
        $identity = $current->requireIdentity();

        AccountView::requireMatch($current, $ifMatch, required: true);

        $before = AccountView::full($current);

        try {
            $this->domains->withNamespaceLock(function () use ($actor, $current, $identity, $before): void {
                $plan = $this->catchall->forDeletedAccount($current->storedUsername, $identity);

                if ($plan->changesAnything() && !$this->rows->isUnchanged($plan->remove)) {
                    throw AccountOperationFailed::changedMeanwhile(CatchallState::alias($identity->domain));
                }

                try {
                    $this->deleteRows($actor, $current, $identity, $before, $plan);
                } catch (Throwable $error) {
                    // Still under the lock. Only a step that started writing has
                    // anything to set back — and only while the account stays.
                    if ($plan->changesAnything() && !$this->rowsAreGone($current->storedUsername)) {
                        $this->rows->restore(
                            self::OPERATION,
                            CatchallState::alias($identity->domain),
                            $plan->remove,
                            $plan->insert,
                            $error,
                        );
                    }

                    throw $error;
                }
            });
        } catch (Throwable $error) {
            if (!$this->rowsAreGone($current->storedUsername)) {
                throw $error;
            }

            $this->recordUnauditedDeletion($actor, $current->storedUsername, $error);
        }

        try {
            $this->mailstore->deleteMailbox($identity);
        } catch (MailstoreFailure $failure) {
            $this->logger->error('An account was deleted and its mailbox could not be removed.', [
                'admin' => $actor->username,
                'account' => $current->storedUsername,
                'reason' => $failure->errorCode(),
            ]);

            $this->repairs->record(new RepairRequest(
                operation: self::OPERATION,
                step: 'mailstore.delete_mailbox',
                subject: $identity->imapMailbox(),
                intended: sprintf('remove %s after its account was deleted', $identity->imapMailbox()),
                reached: 'the account rows are gone; the mailbox still exists and receives nothing',
                reason: $failure->errorCode(),
                parameters: [
                    'account' => $identity->cyrusUsername(),
                    'domain' => $identity->domain->toString(),
                ],
            ));
        }
    }

    /**
     * The database step: catchall first (insert, then remove), then the
     * account, then the audit entries — one transaction.
     *
     * @param array<string, mixed> $before
     *
     * @throws Throwable
     */
    private function deleteRows(
        ActorContext $actor,
        AccountRecord $current,
        MailboxIdentity $identity,
        array $before,
        CatchallCleanupPlan $plan,
    ): void {
        $this->store->atomically(function () use ($actor, $current, $identity, $before, $plan): void {
            foreach ($plan->insert as $row) {
                $this->addresses->insert($row);
            }

            foreach ($plan->remove as $row) {
                if (!$this->addresses->remove($row)) {
                    throw AccountOperationFailed::changedMeanwhile(CatchallState::alias($identity->domain));
                }
            }

            $removed = $this->store->delete($current->storedUsername, $current->domain);

            $operation = $this->audit->record(AuditEntry::removal(
                $actor,
                self::OPERATION,
                'account',
                $current->storedUsername,
                before: [...$before, 'virtual_rows_removed' => $removed],
                domain: $current->domain,
            ));

            $catchall = $plan->auditEntry($actor);

            if ($catchall !== null) {
                $this->audit->record($catchall->partOf($operation));
            }
        });
    }

    /**
     * After a failed database step: did the `DELETE`s stand all the same? A
     * database that cannot answer counts as "not known to be gone" — the
     * mailbox is only removed under rows that are known to be gone.
     */
    private function rowsAreGone(string $storedUsername): bool
    {
        try {
            return $this->store->find($storedUsername) === null;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordUnauditedDeletion(ActorContext $actor, string $storedUsername, Throwable $error): void
    {
        $this->logger->error('An account was deleted and its audit entry could not be written.', [
            'admin' => $actor->username,
            'account' => $storedUsername,
            'reason' => $error->getMessage(),
        ]);

        // The mailbox step must run whatever happens here: a broken database can
        // refuse this entry as well, and the log line above is then all there is.
        try {
            $this->repairs->record(new RepairRequest(
                operation: self::OPERATION,
                step: 'audit.record',
                subject: $storedUsername,
                intended: sprintf('delete account %s and record the deletion in the audit log', $storedUsername),
                reached: sprintf(
                    'the rows of %s are gone (the legacy tables did not roll back) and no audit entry records it',
                    $storedUsername,
                ),
                reason: 'the database step failed after the rows were removed',
            ));
        } catch (Throwable $repairError) {
            $this->logger->error('The repair entry for an unaudited account deletion could not be written.', [
                'account' => $storedUsername,
                'reason' => $repairError->getMessage(),
            ]);
        }
    }
}

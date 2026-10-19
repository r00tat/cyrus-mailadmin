<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one way A3 changes `virtual`: remove these rows, insert those, record it
 * — and if any of that fails, put the old rows back (U-105).
 *
 * **Why every change is set back, deletions included.** The table is MyISAM
 * (O-11): a failing audit insert does not undo the statements before it. A2 lets
 * an account deletion stand, because the mailbox step comes after it; here there
 * is no second system, and restoring a removed alias restores exactly the
 * delivery the client saw a moment ago. So the rule U-85 set for the quota holds
 * for every A3 write: no change stands without its record.
 *
 * The set-back works from counts, not from the assumption that nothing ran: for
 * every row involved, it makes the number of identical rows in the table what
 * it was before. On InnoDB, where the rollback already did that, it finds
 * nothing to do. If the set-back fails as well, the state is a repair-queue
 * entry and an `error` log line — never a silent difference.
 *
 * **Insert first, then remove.** On MyISAM, Postfix sees every statement the
 * moment it runs. Removing first would leave a moment in which the address has
 * no row — and Postfix rejects mail for an unknown recipient of a local domain
 * with a permanent error. Inserting first leaves a moment in which old and new
 * rows both answer, and the mail goes to the union of their destinations: at
 * worst one copy too many, never one too few — the rule of architecture 8.1
 * ("so scheitern, dass niemand Mail verliert") applied within one table.
 */
final readonly class RowWriter
{
    public function __construct(
        private AddressStore $store,
        private AuditWriter $audit,
        private RepairQueue $repairs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<VirtualRow> $remove the rows as they are now
     * @param list<VirtualRow> $insert the rows as they will be
     *
     * @throws Throwable when the change or its audit entry cannot be written;
     *                   the old rows are back (or a repair entry says why not)
     */
    public function replace(string $operation, string $subject, array $remove, array $insert, AuditEntry $entry): void
    {
        // Before anything is written: are the rows still the rows that were
        // read? Read under the namespace lock a moment ago, but webcyradm and a
        // hand-made statement do not take it. A row that is gone is not set
        // back by anyone — refusing here is what keeps it gone.
        if (!$this->isUnchanged($remove)) {
            throw self::changedMeanwhile($subject);
        }

        try {
            $this->store->atomically(function () use ($subject, $remove, $insert, $entry): void {
                foreach ($insert as $row) {
                    $this->store->insert($row);
                }

                foreach ($remove as $row) {
                    if (!$this->store->remove($row)) {
                        throw self::changedMeanwhile($subject);
                    }
                }

                $this->audit->record($entry);
            });
        } catch (Throwable $error) {
            $this->restore($operation, $subject, $remove, $insert, $error);

            throw $error;
        }
    }

    /**
     * Are the rows still in the table, as often as they were read? The check
     * {@see replace()} makes before it writes, for a caller that writes the
     * rows itself — `DeleteAccount` for the catchall (U-128).
     *
     * @param list<VirtualRow> $rows
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function isUnchanged(array $rows): bool
    {
        foreach (self::wanted($rows, []) as ['row' => $row, 'count' => $wanted]) {
            if ($this->store->count($row) < $wanted) {
                return false;
            }
        }

        return true;
    }

    private static function changedMeanwhile(string $subject): AddressOperationFailed
    {
        return AddressOperationFailed::notEditable(
            sprintf('The rows of "%s"', $subject),
            'they changed while they were being written',
        );
    }

    /**
     * Make the table hold `$before` again instead of `$after`, counting rows
     * rather than assuming what ran — the set-back {@see replace()} does after
     * a failure, public for a caller that wrote the rows itself (U-128). On
     * InnoDB after a rollback it finds nothing to do. When it fails as well,
     * the state is a repair-queue entry and an `error` log line.
     *
     * @param list<VirtualRow> $before
     * @param list<VirtualRow> $after
     */
    public function restore(string $operation, string $subject, array $before, array $after, Throwable $cause): void
    {
        $this->logger->error('An address change could not be completed and is being set back.', [
            'operation' => $operation,
            'subject' => $subject,
            'reason' => $cause->getMessage(),
        ]);

        try {
            foreach (self::wanted($before, $after) as ['row' => $row, 'count' => $wanted]) {
                $present = $this->store->count($row);

                for ($i = $present; $i > $wanted; --$i) {
                    $this->store->remove($row);
                }

                for ($i = $present; $i < $wanted; ++$i) {
                    $this->store->insert($row);
                }
            }
        } catch (Throwable $error) {
            $this->logger->error('An address change could not be set back.', [
                'operation' => $operation,
                'subject' => $subject,
                'reason' => $error->getMessage(),
            ]);

            try {
                $this->repairs->record(new RepairRequest(
                    operation: $operation,
                    step: 'virtual.restore',
                    subject: $subject,
                    intended: sprintf('change the rows of %s and record the change in the audit log', $subject),
                    reached: sprintf(
                        'the rows of %s may be partly changed and no audit entry records it',
                        $subject,
                    ),
                    reason: 'the database failed during the change and again while setting it back',
                ));
            } catch (Throwable $repairError) {
                $this->logger->error('The repair entry for an address change could not be written.', [
                    'subject' => $subject,
                    'reason' => $repairError->getMessage(),
                ]);
            }
        }
    }

    /**
     * For every distinct row involved, how many copies the table held before.
     *
     * @param list<VirtualRow> $before
     * @param list<VirtualRow> $after
     *
     * @return list<array{row: VirtualRow, count: int}>
     */
    private static function wanted(array $before, array $after): array
    {
        $wanted = [];

        foreach ($after as $row) {
            $wanted[EntryKey::of($row)] ??= ['row' => $row, 'count' => 0];
        }

        foreach ($before as $row) {
            $key = EntryKey::of($row);
            $wanted[$key] ??= ['row' => $row, 'count' => 0];
            ++$wanted[$key]['count'];
        }

        return array_values($wanted);
    }
}

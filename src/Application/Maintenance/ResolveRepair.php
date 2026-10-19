<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Runtime\Clock;

/**
 * `POST /maintenance/repairs/{repair}/resolve`: an administrator says the entry
 * is handled — by hand, elsewhere, or not at all because it no longer matters.
 *
 * Nothing in Cyrus or the legacy tables is touched. The entry is closed as
 * `dismissed` with the administrator's name and an optional note, and the audit
 * trail records it (`repair.resolve`). This is the only way to close an entry
 * the application cannot retry (U-118).
 *
 * It holds the entry's lock, like {@see RetryRepair}: a dismissal never lands
 * while a retry of the same entry is acting on Cyrus.
 */
final readonly class ResolveRepair
{
    public const string ACTION = 'repair.resolve';

    public function __construct(
        private RepairLedger $ledger,
        private AuditWriter $audit,
        private Clock $clock,
    ) {
    }

    /**
     * @throws RepairOperationFailed
     */
    public function handle(ActorContext $actor, int $id, string $note = ''): RepairEntry
    {
        return $this->ledger->withEntryLock($id, function () use ($actor, $id, $note): RepairEntry {
            $entry = $this->ledger->find($id) ?? throw RepairOperationFailed::notFound($id);

            if (!$entry->isOpen()) {
                throw RepairOperationFailed::alreadyResolved($id);
            }

            $closed = $this->ledger->resolve(
                $id,
                RepairResolution::Dismissed,
                $actor->username,
                trim($note),
                $this->clock->now(),
            );

            if (!$closed) {
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
}

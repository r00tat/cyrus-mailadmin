<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditEntryId;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * An audit writer that fails the way a full or broken `mailadmin_audit` table
 * does, for the paths U-63 says must react to it.
 *
 * By default only entries with the outcome `succeeded` are refused — the ones
 * written inside an operation's transaction — and everything else is handed to
 * the in-memory trail, so a test can see that the `failed` entry written after
 * the rollback still arrives. {@see everything()} refuses every entry, for the
 * case where the failure entry itself cannot be written.
 */
final readonly class RefusingAuditWriter implements AuditWriter
{
    private function __construct(
        private InMemoryAuditTrail $trail,
        private bool $refuseEverything,
    ) {
    }

    public static function successes(InMemoryAuditTrail $trail): self
    {
        return new self($trail, false);
    }

    public static function everything(InMemoryAuditTrail $trail): self
    {
        return new self($trail, true);
    }

    public function record(AuditEntry $entry): AuditEntryId
    {
        if ($this->refuseEverything || $entry->outcome === AuditOutcome::Succeeded) {
            throw PersistenceError::transactionMisuse('the audit table refused the entry');
        }

        return $this->trail->record($entry);
    }
}

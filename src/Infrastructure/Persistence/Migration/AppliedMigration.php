<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One row of `mailadmin_schema_version`: a migration that has run.
 *
 * `appliedAt` is read as UTC because the column is written as UTC — the
 * connection sets `time_zone = '+00:00'` and the column is a `DATETIME`, not a
 * `TIMESTAMP` (U-14). A `TIMESTAMP` would be converted on the way in and on the
 * way out according to whatever the server's zone happens to be, and a migration
 * history that shifts by an hour twice a year is a history nobody trusts.
 */
final readonly class AppliedMigration
{
    public function __construct(
        public int $version,
        public string $name,
        public string $checksum,
        public DateTimeImmutable $appliedAt,
        public int $durationMs,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var mixed $appliedAt */
        $appliedAt = $row['applied_at'] ?? null;

        return new self(
            version: is_numeric($row['version'] ?? null) ? (int) $row['version'] : 0,
            name: is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
            checksum: is_scalar($row['checksum'] ?? null) ? (string) $row['checksum'] : '',
            appliedAt: new DateTimeImmutable(
                is_string($appliedAt) && $appliedAt !== '' ? $appliedAt : 'now',
                new DateTimeZone('UTC'),
            ),
            durationMs: is_numeric($row['duration_ms'] ?? null) ? (int) $row['duration_ms'] : 0,
        );
    }

    public function formattedNumber(): string
    {
        return sprintf('%04d', $this->version);
    }
}

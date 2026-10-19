<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

/**
 * What the database looks like compared with the migrations this build carries.
 *
 * Computed once and passed around: the guard needs it to decide whether to serve
 * requests, `bin/migrate.php status` prints it, and the migrator uses it to
 * decide what to run.
 *
 * A **missing version table means an unmigrated legacy database**, which is the
 * normal case the first time (architecture 10.2). It is not an error and not a
 * reason to refuse anything but service — it is the reason `bin/migrate.php`
 * exists.
 */
final readonly class SchemaState
{
    /**
     * @param list<AppliedMigration> $applied ordered by version
     * @param list<Migration>        $pending ordered by number
     * @param list<AppliedMigration> $unknown applied here but not shipped by this build
     * @param list<Migration>        $modified applied, but the file has changed since
     */
    public function __construct(
        public bool $versionTableExists,
        public array $applied,
        public array $pending,
        public array $unknown = [],
        public array $modified = [],
    ) {
    }

    /**
     * @param list<AppliedMigration> $applied
     * @param list<Migration>        $available
     */
    public static function compare(bool $versionTableExists, array $applied, array $available): self
    {
        $appliedByVersion = [];
        foreach ($applied as $entry) {
            $appliedByVersion[$entry->version] = $entry;
        }

        $pending = [];
        $modified = [];
        $availableNumbers = [];

        foreach ($available as $migration) {
            $availableNumbers[$migration->number] = true;

            $record = $appliedByVersion[$migration->number] ?? null;

            if ($record === null) {
                $pending[] = $migration;

                continue;
            }

            // Recorded, but the file is not the one that ran. Reported, never
            // repaired: re-running it is precisely what the additive rule forbids.
            if ($record->checksum !== '' && $record->checksum !== $migration->checksum) {
                $modified[] = $migration;
            }
        }

        $unknown = array_values(array_filter(
            $applied,
            static fn (AppliedMigration $entry): bool => !isset($availableNumbers[$entry->version]),
        ));

        usort($applied, static fn (AppliedMigration $a, AppliedMigration $b): int
            => $a->version <=> $b->version);

        return new self($versionTableExists, $applied, $pending, $unknown, $modified);
    }

    public function isUpToDate(): bool
    {
        return $this->pending === [];
    }

    /**
     * No version table at all: the legacy database, untouched.
     */
    public function isUnmigrated(): bool
    {
        return !$this->versionTableExists;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * The highest number the database has recorded. `null` when none.
     */
    public function currentVersion(): ?int
    {
        $key = array_key_last($this->applied);

        return $key === null ? null : $this->applied[$key]->version;
    }

    /**
     * The highest number this build carries — applied or not.
     */
    public function latestAvailableVersion(): ?int
    {
        $key = array_key_last($this->pending);

        if ($key === null) {
            return $this->currentVersion();
        }

        return max($this->pending[$key]->number, $this->currentVersion() ?? 0);
    }

    /**
     * A sentence for a log line or an operator's terminal. No host, no DSN.
     */
    public function summary(): string
    {
        if ($this->isUnmigrated()) {
            return sprintf(
                'no schema version table; %d migration(s) to apply',
                $this->pendingCount(),
            );
        }

        if ($this->isUpToDate()) {
            return sprintf('up to date at %04d', $this->currentVersion() ?? 0);
        }

        return sprintf(
            'at %04d, %d migration(s) behind',
            $this->currentVersion() ?? 0,
            $this->pendingCount(),
        );
    }
}

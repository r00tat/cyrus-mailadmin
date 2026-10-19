<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

/**
 * Reads `migrations/*.sql` into an ordered list.
 *
 * Ordering is by number, not by file name, and the number is the identity of a
 * migration. That is what makes the reserved ranges in U-6 work: F3 owns
 * 0001–0009, S2 owns 0010–0019, and a gap between them is not a problem,
 * because the version table records which numbers ran rather than how far the
 * counter got. A migration that is merged late still runs.
 *
 * Two numbers that collide is an error, not a resolved ambiguity — it means two
 * packages ignored their range, and picking one of the two files silently is
 * how a table ends up missing on one installation and present on another.
 */
final readonly class MigrationLoader
{
    public function __construct(private string $directory)
    {
    }

    /**
     * @return list<Migration> ordered by number
     *
     * @throws MigrationError
     */
    public function load(): array
    {
        if (!is_dir($this->directory)) {
            throw MigrationError::directoryMissing($this->directory);
        }

        $files = glob(rtrim($this->directory, '/') . '/*.sql');

        if ($files === false) {
            throw MigrationError::directoryMissing($this->directory);
        }

        sort($files, SORT_STRING);

        $migrations = [];

        /** @var array<int, string> $seen */
        $seen = [];

        foreach ($files as $path) {
            $fileName = basename($path);
            $sql = @file_get_contents($path);

            if ($sql === false) {
                throw MigrationError::unreadable($path);
            }

            $migration = Migration::fromFile($fileName, $sql);

            if (isset($seen[$migration->number])) {
                throw MigrationError::duplicateNumber(
                    $migration->number,
                    $seen[$migration->number],
                    $fileName,
                );
            }

            $seen[$migration->number] = $fileName;
            $migrations[] = $migration;
        }

        usort(
            $migrations,
            static fn (Migration $a, Migration $b): int => $a->number <=> $b->number,
        );

        return $migrations;
    }

    /**
     * The numbers only, from the file names — without opening a single file.
     *
     * {@see load()} reads every file to compute its checksum, which is right for
     * a migration run and wrong for a check that happens on every request. The
     * guard needs to know *which* migrations exist, not what is in them.
     *
     * @return list<int> ascending
     *
     * @throws MigrationError
     */
    public function numbers(): array
    {
        if (!is_dir($this->directory)) {
            throw MigrationError::directoryMissing($this->directory);
        }

        $files = glob(rtrim($this->directory, '/') . '/*.sql');

        if ($files === false) {
            throw MigrationError::directoryMissing($this->directory);
        }

        $numbers = [];

        foreach ($files as $path) {
            $fileName = basename($path);

            if (preg_match('/^(\d{4})_[a-z0-9][a-z0-9_]*\.sql$/', $fileName, $matches) !== 1) {
                throw MigrationError::misnamed($fileName);
            }

            $numbers[] = (int) $matches[1];
        }

        sort($numbers);

        return $numbers;
    }

    public function directory(): string
    {
        return $this->directory;
    }
}

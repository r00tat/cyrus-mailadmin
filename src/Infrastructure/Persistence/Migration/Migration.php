<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

/**
 * One numbered migration file, parsed into statements.
 *
 * The number is the order and the identity: the version table records numbers,
 * not a high-water mark, so a gap in the numbering is harmless and a migration
 * that arrives late still runs (U-6).
 *
 * The checksum is recorded next to the number when the migration is applied.
 * It is **not** verified on later runs — a mismatch would mean an already
 * applied file was edited afterwards, which the migrator can report but must not
 * act on: re-running it is exactly what the additive rule forbids. `bin/migrate.php
 * status` shows the difference; what to do about it is a human decision.
 */
final readonly class Migration
{
    /**
     * @param list<string> $statements comments stripped, in file order
     */
    private function __construct(
        public int $number,
        public string $name,
        public string $fileName,
        public array $statements,
        public string $checksum,
    ) {
    }

    /**
     * @throws MigrationError when the name does not match `NNNN_description.sql` or there is
     *                        no statement in the file
     */
    public static function fromFile(string $fileName, string $sql): self
    {
        if (preg_match('/^(\d{4})_([a-z0-9][a-z0-9_]*)\.sql$/', $fileName, $matches) !== 1) {
            throw MigrationError::misnamed($fileName);
        }

        $statements = SqlStatements::split($sql);

        if ($statements === []) {
            throw MigrationError::empty($fileName);
        }

        return new self(
            number: (int) $matches[1],
            name: $matches[2],
            fileName: $fileName,
            statements: $statements,
            // Over the raw file, not over the parsed statements: an operator
            // comparing checksums has the file in front of them, not the parse.
            checksum: hash('sha256', $sql),
        );
    }

    /**
     * `0001` — how the number appears in file names and in the version table.
     */
    public function formattedNumber(): string
    {
        return sprintf('%04d', $this->number);
    }

    /**
     * `0001 schema_version` — for a log line or a status listing.
     */
    public function label(): string
    {
        return $this->formattedNumber() . ' ' . $this->name;
    }
}

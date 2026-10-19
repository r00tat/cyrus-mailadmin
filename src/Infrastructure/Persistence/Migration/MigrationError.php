<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Throwable;

/**
 * Everything that can go wrong before, during and after a migration.
 *
 * The messages are long. They are read by an operator at three in the morning
 * with a half-migrated database, and the thing that saves time then is knowing
 * which file, which statement and what to do — not brevity.
 */
final class MigrationError extends PersistenceError
{
    public static function directoryMissing(string $directory): self
    {
        return new self(ErrorCode::MIGRATION_INVALID, sprintf(
            'Migration directory %s does not exist.',
            $directory,
        ));
    }

    public static function unreadable(string $file): self
    {
        return new self(ErrorCode::MIGRATION_INVALID, sprintf(
            'Migration file %s cannot be read.',
            $file,
        ));
    }

    public static function misnamed(string $file): self
    {
        return new self(ErrorCode::MIGRATION_INVALID, sprintf(
            'Migration file "%s" does not match NNNN_description.sql (U-6). The four digits '
            . 'decide the order and the reserved range decides which package owns the file.',
            $file,
        ));
    }

    public static function duplicateNumber(int $number, string $first, string $second): self
    {
        return new self(ErrorCode::MIGRATION_INVALID, sprintf(
            'Migrations %s and %s share the number %04d. The reserved ranges in U-6 exist so that '
            . 'two packages cannot collide; one of the two is outside its range.',
            $first,
            $second,
            $number,
        ));
    }

    public static function empty(string $file): self
    {
        return new self(ErrorCode::MIGRATION_INVALID, sprintf(
            'Migration %s contains no statement. An empty migration would be recorded as applied '
            . 'and could never be filled in afterwards.',
            $file,
        ));
    }

    /**
     * @param list<string> $violations
     */
    public static function rejected(string $file, array $violations): self
    {
        return new self(ErrorCode::MIGRATION_REJECTED, sprintf(
            "Migration %s violates the additive-only rule (E-1, CLAUDE.md rule 1):\n  - %s",
            $file,
            implode("\n  - ", $violations),
        ));
    }

    public static function statementFailed(
        string $file,
        int $position,
        string $statement,
        Throwable $previous,
    ): self {
        return new self(ErrorCode::MIGRATION_FAILED, sprintf(
            "Migration %s failed at statement %d:\n  %s\n  %s\n"
            . 'The migration was not recorded, so the next run repeats it from the beginning. '
            . 'Every statement in a migration must be idempotent for that to be safe.',
            $file,
            $position,
            self::abbreviate($statement),
            $previous->getMessage(),
        ), $previous);
    }

    public static function lockUnavailable(string $name, int $timeoutSeconds): self
    {
        return new self(ErrorCode::MIGRATION_LOCKED, sprintf(
            'Another process holds the migration lock "%s"; waited %d seconds. Nothing was '
            . 'changed. Run the command again once the other migration has finished.',
            $name,
            $timeoutSeconds,
        ));
    }

    public static function lockFailed(string $name, Throwable $previous): self
    {
        return new self(ErrorCode::MIGRATION_LOCKED, sprintf(
            'Could not acquire the migration lock "%s": %s',
            $name,
            $previous->getMessage(),
        ), $previous);
    }

    public static function versionTableUnreadable(Throwable $previous): self
    {
        return new self(ErrorCode::MIGRATION_FAILED, sprintf(
            'The schema version table could not be read: %s',
            $previous->getMessage(),
        ), $previous);
    }

    public static function autoMigrateWithoutLock(): self
    {
        return new self(
            ErrorCode::MIGRATION_LOCKED,
            'MAILADMIN_AUTO_MIGRATE is on but the lock could not be taken, so this request did '
            . 'not migrate. Another process is doing it; the request is refused rather than '
            . 'served against a half-migrated schema.',
        );
    }

    private static function abbreviate(string $statement): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $statement));

        return strlen($collapsed) <= 200 ? $collapsed : substr($collapsed, 0, 197) . '...';
    }
}

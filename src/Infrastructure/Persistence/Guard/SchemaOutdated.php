<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Guard;

use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\Migration\SchemaVersionTable;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * The database is behind the migrations this build ships (architecture 10.2).
 *
 * The one persistence error that reaches a client, as `503` with
 * `migration.schema_outdated`. Serving anyway would mean handlers querying
 * columns that do not exist yet — an error per endpoint instead of one clear
 * statement about the deployment.
 */
final class SchemaOutdated extends PersistenceError
{
    /**
     * @param list<int> $pending
     */
    public static function behind(array $pending, bool $versionTableExists): self
    {
        $numbers = implode(', ', array_map(
            static fn (int $number): string => sprintf('%04d', $number),
            $pending,
        ));

        return new self(ErrorCode::SCHEMA_OUTDATED, $versionTableExists
            ? sprintf(
                'The database schema is behind this build: migration(s) %s have not been applied. '
                . 'Run "php bin/migrate.php" as part of the deployment.',
                $numbers,
            )
            : sprintf(
                'The database has no %s table, so it has never been migrated. This is the normal '
                . 'state of a webcyradm installation before the first run. Run '
                . '"php bin/migrate.php" to apply migration(s) %s.',
                SchemaVersionTable::NAME,
                $numbers,
            ));
    }
}

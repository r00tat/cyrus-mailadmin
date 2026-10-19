<?php

declare(strict_types=1);

/**
 * Applies the pending migrations, under the database lock (architecture 10.2).
 *
 *     php bin/migrate.php            apply everything that is pending
 *     php bin/migrate.php status     show the state and change nothing
 *     php bin/migrate.php --dry-run  list what would run, and check the rules
 *
 * This is the deployment's job. The web application only *checks* the version
 * and refuses service when it is behind; it does not migrate (unless
 * MAILADMIN_AUTO_MIGRATE is on, which is off by default).
 *
 * Running it twice is harmless: the second run finds nothing pending. Running it
 * twice *at the same time* is harmless too — the second process waits for
 * `GET_LOCK`, then finds nothing pending.
 *
 * Exit codes, so that a deployment script can branch on them:
 *
 *   0  nothing to do, or everything applied
 *   1  configuration or database error
 *   2  another process holds the lock; nothing was changed
 *   3  a migration was refused by the additive-only rule
 *   4  a migration failed while running
 *
 * `status` also exits 0 when migrations are pending — it reports, it does not
 * judge. Use `--dry-run` in a check that should fail on a pending migration.
 */

use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\Migration\Migrator;
use Mailadmin\Infrastructure\Persistence\Migration\SchemaState;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Container\ContainerInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit(1);
}

/** @var ContainerInterface $container */
$container = require __DIR__ . '/bootstrap.php';

$arguments = array_slice($argv ?? [], 1);
$command = $arguments[0] ?? 'up';
$dryRun = in_array('--dry-run', $arguments, true);

if (in_array($command, ['-h', '--help', 'help'], true)) {
    fwrite(STDOUT, <<<'TEXT'
        Usage: php bin/migrate.php [status] [--dry-run]

          (no argument)  apply every pending migration, under the database lock
          status         print the state and change nothing
          --dry-run      list the pending migrations and verify the rules only

        TEXT);

    exit(0);
}

$printState = static function (SchemaState $state): void {
    fwrite(STDOUT, 'Schema: ' . $state->summary() . PHP_EOL);

    foreach ($state->applied as $entry) {
        fwrite(STDOUT, sprintf(
            '  [x] %s %-30s %s UTC%s',
            $entry->formattedNumber(),
            $entry->name,
            $entry->appliedAt->format('Y-m-d H:i:s'),
            PHP_EOL,
        ));
    }

    foreach ($state->pending as $migration) {
        fwrite(STDOUT, sprintf(
            '  [ ] %s %-30s pending%s',
            $migration->formattedNumber(),
            $migration->name,
            PHP_EOL,
        ));
    }

    foreach ($state->unknown as $entry) {
        fwrite(STDERR, sprintf(
            '  [?] %s %s was applied here but is not part of this build. A newer version ran '
            . 'against this database.%s',
            $entry->formattedNumber(),
            $entry->name,
            PHP_EOL,
        ));
    }

    foreach ($state->modified as $migration) {
        fwrite(STDERR, sprintf(
            '  [!] %s %s has changed since it was applied. It is NOT re-run; compare the file '
            . 'with what the database actually has.%s',
            $migration->formattedNumber(),
            $migration->name,
            PHP_EOL,
        ));
    }
};

try {
    $migrator = $container->get(Migrator::class);

    if ($command === 'status' || $dryRun) {
        $state = $migrator->state();
        $printState($state);

        if ($dryRun) {
            fwrite(STDOUT, sprintf(
                'Dry run: %d migration(s) would be applied. Nothing was changed.%s',
                $state->pendingCount(),
                PHP_EOL,
            ));

            exit($state->isUpToDate() ? 0 : 1);
        }

        exit(0);
    }

    $applied = $migrator->migrate(Migrator::BY_CLI);

    if ($applied === []) {
        fwrite(STDOUT, 'Nothing to do; the schema is up to date.' . PHP_EOL);

        exit(0);
    }

    foreach ($applied as $migration) {
        fwrite(STDOUT, 'Applied ' . $migration->label() . PHP_EOL);
    }

    fwrite(STDOUT, sprintf('%d migration(s) applied.%s', count($applied), PHP_EOL));

    exit(0);
} catch (PersistenceError $error) {
    // The message names files, statements and migration numbers. It never names
    // a credential: the DSN is redacted at the source (DatabaseConfiguration).
    fwrite(STDERR, $error->getMessage() . PHP_EOL);

    exit(match ($error->errorCode()) {
        ErrorCode::MIGRATION_LOCKED => 2,
        ErrorCode::MIGRATION_REJECTED, ErrorCode::MIGRATION_INVALID => 3,
        ErrorCode::MIGRATION_FAILED => 4,
        default => 1,
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration aborted: ' . $error->getMessage() . PHP_EOL);

    exit(1);
}

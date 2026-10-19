<?php

declare(strict_types=1);

/**
 * Reports what the target database actually looks like. Reads only.
 *
 *     php bin/db-check.php
 *
 * F3's first open point says the character set and collation of the new tables
 * have to match the existing ones, and that this must be **checked on the target
 * system before the first migration**. A note in a document is not a check, so
 * this is the check.
 *
 * It prints four things:
 *
 *   1. Whether the connection works at all, with the DSN redacted.
 *   2. Character set, collation and **storage engine** of every legacy table and
 *      the presence of the two views. The engine matters more than it looks:
 *      webcyradm created these tables as MyISAM, and a transaction over a MyISAM
 *      table commits nothing and rolls back nothing — silently. Architecture 8.1
 *      builds on the database step being atomic. If the report says MyISAM,
 *      that assumption does not hold and the tables need converting before it
 *      does.
 *   3. Whether the new tables would join cleanly. They are utf8mb4 /
 *      utf8mb4_unicode_ci; a legacy table in another collation is not a problem
 *      as long as nothing joins across the two, which is the convention F3
 *      documents.
 *   4. Whether the deployed view `allusers` honours `mailadmin_account_status`
 *      (U-127): only then does disabling an account stop its logins. The view
 *      belongs to Salt; mailadmin only reads its definition (`SHOW VIEW`) and,
 *      without that privilege, tests its behaviour on a disabled account.
 *   5. The migration state, as `bin/migrate.php status` would show it.
 *
 * Exit code 0 when everything could be read, 1 when the database is not
 * reachable, 2 when something was found that needs a decision.
 */

use Mailadmin\Infrastructure\Persistence\AccountStatusEnforcement;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\DatabaseConfiguration;
use Mailadmin\Infrastructure\Persistence\LegacySchema;
use Mailadmin\Infrastructure\Persistence\Migration\Migrator;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Container\ContainerInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit(1);
}

/** @var ContainerInterface $container */
$container = require __DIR__ . '/bootstrap.php';

$warnings = 0;

$line = static function (string $text = ''): void {
    fwrite(STDOUT, $text . PHP_EOL);
};

try {
    $connection = $container->get(Connection::class);
    $connection->pdo();

    $line('Connection: ok (' . $connection->describe() . ')');
} catch (PersistenceError $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);

    exit(1);
}

$line();
$line('Legacy objects');
$line('--------------');

/** @var list<array<string, mixed>> $tables */
$tables = $connection->select(
    'SELECT table_name, engine, table_collation, table_type '
    . 'FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
);

$byName = [];
foreach ($tables as $row) {
    $name = is_scalar($row['table_name'] ?? null) ? strtolower((string) $row['table_name']) : '';
    if ($name !== '') {
        $byName[$name] = $row;
    }
}

foreach (LegacySchema::TABLES as $name) {
    $row = $byName[$name] ?? null;

    if ($row === null) {
        $line(sprintf('  %-14s absent', $name));

        continue;
    }

    $engine = is_scalar($row['engine'] ?? null) ? (string) $row['engine'] : '?';
    $collation = is_scalar($row['table_collation'] ?? null) ? (string) $row['table_collation'] : '?';

    $note = '';
    if (strtoupper($engine) !== 'INNODB') {
        $note = '  <-- not transactional; architecture 8.1 assumes the database step is atomic';
        ++$warnings;
    }

    $line(sprintf('  %-14s engine=%-8s collation=%s%s', $name, $engine, $collation, $note));
}

foreach (LegacySchema::VIEWS as $name) {
    $present = isset($byName[$name]);
    $line(sprintf('  %-14s %s', $name, $present ? 'present (Salt owns it)' : 'ABSENT'));

    if (!$present) {
        ++$warnings;
    }
}

$line();
$line('Password columns');
$line('----------------');
$line('Migration 0003 widens these to VARCHAR(255) and restates latin1 / latin1_bin.');
$line('A column that is already utf8mb4 would be re-encoded by it — harmless for every');
$line('hash format, not for a plaintext password with a non-ASCII character (O-16).');
$line();

foreach (LegacySchema::WIDENABLE_COLUMNS as $qualified => $declaredWidth) {
    [$table, $column] = explode('.', $qualified, 2);

    /** @var array<string, mixed>|null $definition */
    $definition = $connection->selectOne(
        'SELECT character_maximum_length AS width, character_set_name AS charset, '
        . 'collation_name AS collation FROM information_schema.columns '
        . 'WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
        ['t' => $table, 'c' => $column],
    );

    if ($definition === null) {
        $line(sprintf('  %-22s absent', $qualified));
        ++$warnings;

        continue;
    }

    $width = is_scalar($definition['width'] ?? null) ? (int) $definition['width'] : 0;
    $charset = is_scalar($definition['charset'] ?? null) ? (string) $definition['charset'] : '?';
    $collation = is_scalar($definition['collation'] ?? null) ? (string) $definition['collation'] : '?';

    $note = '';

    if ($charset !== 'latin1') {
        $note = '  <-- not latin1; migration 0003 would re-encode. Decide before migrating (O-16)';
        ++$warnings;
    } elseif ($width <= $declaredWidth) {
        $note = '  <-- too narrow for bcrypt or Argon2id; migration 0003 has not run';
    }

    $line(sprintf(
        '  %-22s VARCHAR(%d) %s / %s%s',
        $qualified,
        $width,
        $charset,
        $collation,
        $note,
    ));
}

$line();
$line('New tables would use: utf8mb4 / utf8mb4_unicode_ci / InnoDB.');
$line('A differing collation above is not a defect: mailadmin_ tables are not joined to');
$line('legacy tables, with one exception whose column is declared to match (below).');
$line('Keep it that way: look the two up separately and join in PHP.');

$line();
$line('Account status (U-127)');
$line('----------------------');
$line('allusers must leave out accounts disabled in mailadmin_account_status. Order of');
$line('deployment: migration 0060 first, then the view (docs/deployment/allusers.sql.example).');
$line();

$statusTable = isset($byName['mailadmin_account_status']);
$line(sprintf('  %-34s %s', 'mailadmin_account_status', $statusTable ? 'present' : 'absent (migration 0060 has not run)'));

$columnCollation = static function (string $table, string $column) use ($connection): string {
    $value = $connection->selectValue(
        'SELECT collation_name FROM information_schema.columns '
        . 'WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
        ['t' => $table, 'c' => $column],
    );

    return is_scalar($value) ? (string) $value : '?';
};

if ($statusTable) {
    $legacy = $columnCollation('accountuser', 'username');
    $ours = $columnCollation('mailadmin_account_status', 'username');
    $note = '';

    if ($legacy !== $ours && str_starts_with($legacy, 'latin1_')) {
        // Same character set: MariaDB resolves a mix of a _bin and another
        // collation to the _bin one, so the join still compares bytes and
        // raises no "illegal mix" (U-129). Worth knowing, nothing to do.
        $note = '  (differs; same character set, so the join uses latin1_bin and compares bytes)';
    } elseif ($legacy !== $ours) {
        $note = '  <-- differs: another character set; the join converts and may ignore case';
        ++$warnings;
    }

    $line(sprintf('  %-34s accountuser=%s status=%s%s', 'username collation', $legacy, $ours, $note));
}

/** @var array<string, mixed>|null $view */
$view = $connection->selectOne(
    'SELECT view_definition AS definition FROM information_schema.views '
    . 'WHERE table_schema = DATABASE() AND table_name = :v',
    ['v' => 'allusers'],
);

$definition = is_scalar($view['definition'] ?? null) ? (string) $view['definition'] : '';
// Every branch of the UNION, not the definition as a whole (U-129): a view
// that filters one spelling only still lets the other one log in.
$branches = $definition === '' ? null : AccountStatusEnforcement::branches($definition);
$references = $branches === null ? null : $branches['honouring'] > 0;

if ($view === null) {
    $verdict = 'ABSENT';
} elseif ($references === true && !$statusTable) {
    $verdict = 'CRITICAL: the view reads mailadmin_account_status, which does not exist - '
        . 'allusers fails and nobody can log in. Run migration 0060 now';
    ++$warnings;
} elseif ($branches !== null && $branches['honouring'] < $branches['branches']) {
    $verdict = sprintf(
        'does NOT fully honour the status table: only %d of %d UNION branches read it - a disabled '
            . 'account can still log in under the other spelling. Add the filter to EACH branch '
            . '(docs/deployment/allusers.sql.example)',
        $branches['honouring'],
        $branches['branches'],
    );
    ++$warnings;
} elseif ($references === true) {
    $verdict = 'honours the status table in every UNION branch (definition read)';
} elseif ($references === false) {
    $verdict = 'does NOT honour the status table - mailadmin refuses to disable accounts '
        . '(account.status_not_enforced)';
} else {
    $verdict = 'definition not readable (GRANT SHOW VIEW ON allusers to see it)';
}

$line(sprintf('  %-34s %s', 'allusers', $verdict));

if ($statusTable && $view !== null) {
    try {
        $disabled = (int) $connection->selectValue('SELECT COUNT(*) FROM mailadmin_account_status WHERE disabled = 1');
        $line(sprintf('  %-34s %d', 'disabled accounts', $disabled));

        if ($disabled > 0) {
            // Behaviour, whatever the definition says: a disabled account must
            // not be found in the view under its stored name nor under the
            // address form the view derives from it (U-129).
            $leaking = (new AccountStatusEnforcement($connection))->leakingAccounts();

            if ($leaking > 0) {
                $line(sprintf('  %-34s %d  <-- disabled accounts can still log in', 'disabled but in allusers', $leaking));
                ++$warnings;
            } else {
                $line(sprintf('  %-34s none', 'disabled but in allusers'));
            }
        } elseif ($references === null) {
            $line('  No disabled account to test the view with; its behaviour is unknown.');
        }

        $orphans = (int) $connection->selectValue(
            'SELECT COUNT(*) FROM mailadmin_account_status s '
            . 'WHERE NOT EXISTS (SELECT 1 FROM accountuser a WHERE a.username = s.username)',
        );

        if ($orphans > 0) {
            $line(sprintf(
                '  %-34s %d  (accounts deleted outside mailadmin; harmless, cleared when the name is created again)',
                'status rows without account',
                $orphans,
            ));
        }
    } catch (PersistenceError $error) {
        fwrite(STDERR, '  ' . $error->getMessage() . PHP_EOL);
        ++$warnings;
    }
}

$line();
$line('Migration state');
$line('---------------');

try {
    $line('  ' . $container->get(Migrator::class)->state()->summary());
} catch (PersistenceError $error) {
    fwrite(STDERR, '  ' . $error->getMessage() . PHP_EOL);
    ++$warnings;
}

$line();
$line(sprintf(
    'Database name from %s: %s',
    DatabaseConfiguration::DSN,
    $container->get(DatabaseConfiguration::class)->databaseName() ?? '<not in the DSN>',
));

exit($warnings === 0 ? 0 : 2);

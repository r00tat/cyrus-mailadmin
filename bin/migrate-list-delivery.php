<?php

declare(strict_types=1);

/**
 * Moves the mailing lists from the alias-file path to the pipe transport —
 * step 4 of the changeover (spec 6) — or back.
 *
 *     php bin/migrate-list-delivery.php             move legacy rows to the transport
 *     php bin/migrate-list-delivery.php --dry-run   report what would change, write nothing
 *     php bin/migrate-list-delivery.php --revert    move transport rows back to the legacy form
 *
 * `--revert` and `--dry-run` combine.
 *
 * Only `virtual` is written, one row at a time under the namespace lock of
 * the address use cases, each change with an audit entry by
 * `<system:migrate-list-delivery>`. A second run changes nothing. Lists
 * without a `virtual` row are reported, not created; list addresses with a
 * `+` are not moved. {@see \Mailadmin\Application\MailingList\MigrateListDelivery}
 * has the rules.
 *
 * Output: one line per list (`migrated` | `reverted` | `unchanged` |
 * `no virtual entry` | `skipped: <reason>` | `failed: <reason>`), then the
 * totals.
 *
 * A forward run refuses (exit 1) while the transport domain is a managed
 * domain: Postfix would deliver list mail locally. `--revert` still runs.
 *
 * Exit codes: 0 when every list ended without failure, 1 on a configuration
 * or database error, a managed transport domain, or when a list failed, 64 on
 * a wrong invocation.
 */

use Mailadmin\Application\MailingList\MigrateListDelivery;
use Mailadmin\Application\MailingList\TransportDomainIsManaged;
use Mailadmin\Config\ConfigurationError;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Container\ContainerInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit(1);
}

$arguments = array_slice($argv, 1);
$unknown = array_diff($arguments, ['--dry-run', '--revert']);

if ($unknown !== []) {
    fwrite(STDERR, 'Usage: php bin/migrate-list-delivery.php [--dry-run] [--revert]' . PHP_EOL);

    exit(64);
}

$dryRun = in_array('--dry-run', $arguments, true);
$revert = in_array('--revert', $arguments, true);

$line = static function (string $text = ''): void {
    fwrite(STDOUT, $text . PHP_EOL);
};

try {
    /** @var ContainerInterface $container */
    $container = require __DIR__ . '/bootstrap.php';

    $result = $container->get(MigrateListDelivery::class)->handle(
        ActorContext::system('migrate-list-delivery'),
        $revert,
        $dryRun,
    );
} catch (ConfigurationError|TransportDomainIsManaged|PersistenceError $error) {
    // A plain message, no stack trace: these are the operator's to fix.
    fwrite(STDERR, $error->getMessage() . PHP_EOL);

    exit(1);
}

if ($dryRun) {
    $line('Dry run: nothing is written.');
}

$line($revert ? 'Direction: transport -> legacy alias path' : 'Direction: legacy alias path -> transport');

foreach ($result->outcomes as $outcome) {
    $line(sprintf('  %-50s %s', $outcome->address, $outcome->label()));
}

$line();
$line('Totals:');

foreach ($result->totals() as $label => $count) {
    $line(sprintf('  %-18s %d', $label, $count));
}

exit($result->hasFailures() ? 1 : 0);

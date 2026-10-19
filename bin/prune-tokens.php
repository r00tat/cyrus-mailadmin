<?php

declare(strict_types=1);

/**
 * Removes the token and sign-in bookkeeping that has stopped mattering (S2).
 *
 *     php bin/prune-tokens.php            delete what is expired
 *     php bin/prune-tokens.php --dry-run  count it and change nothing
 *
 * Three tables, three different reasons for a row to survive:
 *
 *   * `mailadmin_refresh_token` — kept until `expires_at`, and **not a moment
 *     less**. A consumed token has to stay recognisable for as long as it could
 *     still be replayed, because recognising it is how a theft is detected. Only
 *     rows whose family deadline has passed go.
 *   * `mailadmin_token_denylist` — kept until the last access token it denies
 *     would have expired anyway. After that the row denies nothing.
 *   * `mailadmin_login_attempt` — kept for one rate-limiter window. Older rows
 *     are counted by nothing and are an IP address nobody needs (U-29).
 *
 * ## Why a cron job and not opportunistic cleanup
 *
 * The tempting alternative is to prune with some small probability on each
 * request. It makes response times unpredictable, it makes the behaviour
 * untestable, and it means the cleanup stops when traffic does — which is
 * exactly when a table has stopped being looked at and starts being forgotten.
 * A scheduled command is boring, and its absence is visible in `crontab -l`.
 *
 * Suggested schedule: hourly. Nothing breaks if it does not run — the checks all
 * compare timestamps and an unpruned row is merely dead weight.
 *
 * Exit codes: 0 when it ran, 1 on a configuration or database error.
 */

use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Infrastructure\Security\LoginAttemptLog;
use Mailadmin\Infrastructure\Security\RateLimiter;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Runtime\Clock;
use Psr\Container\ContainerInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit(1);
}

/** @var ContainerInterface $container */
$container = require __DIR__ . '/bootstrap.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$line = static function (string $text = ''): void {
    fwrite(STDOUT, $text . PHP_EOL);
};

try {
    $now = $container->get(Clock::class)->now();
    $attemptCutoff = $now->setTimestamp($now->getTimestamp() - RateLimiter::WINDOW_SECONDS);

    if ($dryRun) {
        $line('Dry run: nothing is deleted.');
        $line(sprintf('  refresh tokens   expired at or before %s', $now->format('Y-m-d H:i:s')));
        $line(sprintf('  denylist rows    expired at or before %s', $now->format('Y-m-d H:i:s')));
        $line(sprintf('  login attempts   older than          %s', $attemptCutoff->format('Y-m-d H:i:s')));

        exit(0);
    }

    $line(sprintf(
        'refresh tokens   removed: %d',
        $container->get(RefreshTokenStore::class)->prune($now),
    ));
    $line(sprintf(
        'denylist rows    removed: %d',
        $container->get(TokenDenylist::class)->prune($now),
    ));
    $line(sprintf(
        'login attempts   removed: %d',
        $container->get(LoginAttemptLog::class)->prune($attemptCutoff),
    ));
} catch (PersistenceError $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);

    exit(1);
}

exit(0);

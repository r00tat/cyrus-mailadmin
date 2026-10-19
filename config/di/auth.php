<?php

declare(strict_types=1);

/**
 * Container definitions for S2 — authentication, tokens and the rate limiter.
 *
 * One file per package (U-5). Only what autowiring cannot resolve is here: the
 * four ports behind their interfaces, and everything that depends on a value
 * from the environment.
 *
 * The use cases — `SignIn`, `RefreshSession`, `SignOut` — are not listed. PHP-DI
 * builds each of them from its constructor once the entries below exist, and the
 * four handlers likewise.
 *
 * **The in-memory doubles are deliberately not wired**, the same way M2's are
 * not (U-37). A container that could be talked into an in-memory administrator
 * directory by an environment variable would be one variable away from an
 * installation that authenticates against nothing. Tests construct them
 * directly.
 */

use Mailadmin\Application\Auth\SessionFactory;
use Mailadmin\Config\Config;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Http\RefreshCookie;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\SqlAdminDirectory;
use Mailadmin\Infrastructure\Persistence\SqlLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\SqlRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\SqlTokenDenylist;
use Mailadmin\Infrastructure\Security\AuthenticationConfiguration;
use Mailadmin\Infrastructure\Security\LoginAttemptLog;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Infrastructure\Security\SigningKeys;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Infrastructure\Security\TokenVerifier;
use Mailadmin\Runtime\Clock;
use Mailadmin\Runtime\Sleeper;
use Mailadmin\Runtime\SystemClock;
use Mailadmin\Runtime\SystemSleeper;
use Psr\Log\LoggerInterface;

return [
    // ------------------------------------------------------------------
    // Time, and waiting. Both exist so that token lifetimes and the growing
    // sign-in delay can be tested without a test suite that waits.
    // ------------------------------------------------------------------
    Clock::class => static fn (): SystemClock => new SystemClock(),
    Sleeper::class => static fn (): SystemSleeper => new SystemSleeper(),

    // ------------------------------------------------------------------
    // Configuration, read once at startup (U-11). Throws here rather than on
    // the first sign-in if the signing key is absent or unusable.
    // ------------------------------------------------------------------
    AuthenticationConfiguration::class => static fn (Config $config): AuthenticationConfiguration
        => AuthenticationConfiguration::fromConfig($config),

    SigningKeys::class => static fn (AuthenticationConfiguration $auth): SigningKeys => $auth->keys,

    TokenIssuer::class => static fn (
        AuthenticationConfiguration $auth,
        Clock $clock,
    ): TokenIssuer => new TokenIssuer(
        keys: $auth->keys,
        clock: $clock,
        issuer: $auth->issuer,
        audience: $auth->audience,
        lifetimeSeconds: $auth->accessTokenTtl,
    ),

    TokenVerifier::class => static fn (
        AuthenticationConfiguration $auth,
        Clock $clock,
    ): TokenVerifier => new TokenVerifier(
        keys: $auth->keys,
        clock: $clock,
        issuer: $auth->issuer,
        audience: $auth->audience,
    ),

    SessionFactory::class => static fn (
        RefreshTokenStore $store,
        Clock $clock,
        AuthenticationConfiguration $auth,
    ): SessionFactory => new SessionFactory(
        store: $store,
        clock: $clock,
        refreshTokenTtl: $auth->refreshTokenTtl,
    ),

    // ------------------------------------------------------------------
    // The four things that talk to the database. Each behind its interface, so
    // that the use cases can be tested against the doubles beside them.
    // ------------------------------------------------------------------
    AdminDirectory::class => static fn (
        Connection $connection,
        LoggerInterface $logger,
    ): SqlAdminDirectory => new SqlAdminDirectory($connection, $logger),

    RefreshTokenStore::class => static fn (Connection $connection): SqlRefreshTokenStore
        => new SqlRefreshTokenStore($connection),

    TokenDenylist::class => static fn (Connection $connection, Clock $clock): SqlTokenDenylist
        => new SqlTokenDenylist($connection, $clock),

    LoginAttemptLog::class => static fn (Connection $connection): SqlLoginAttemptLog
        => new SqlLoginAttemptLog($connection),

    // ------------------------------------------------------------------
    // The cookie needs the mount prefix so that its Path covers `/auth/*` and
    // nothing else, in both deployment variants (U-25).
    // ------------------------------------------------------------------
    RefreshCookie::class => static fn (Config $config): RefreshCookie
        => new RefreshCookie($config->basePath()),
];

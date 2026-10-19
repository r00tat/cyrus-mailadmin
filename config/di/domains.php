<?php

declare(strict_types=1);

/**
 * Container definitions for A1 — domain management.
 *
 * One file per package (U-5). Only what autowiring cannot resolve is here: the
 * two ports behind their interfaces, the configuration value, and the responder,
 * which needs the mount prefix in order to build a correct `Location`.
 *
 * The five use cases and the five handlers are not listed. PHP-DI builds each of
 * them from its constructor once the entries below exist.
 *
 * The repair queue A1 declared is wired in `config/di/maintenance.php` since
 * A6 replaced the log-only stopgap (U-50, U-117).
 *
 * **The double is not wired**, the same way S2's and M2's are not: an
 * installation that could be talked into an in-memory `domain` table would look
 * like it was working while doing nothing. Tests construct it directly.
 */

use Mailadmin\Application\Domain\DomainDefaults;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Config\Config;
use Mailadmin\Http\Handler\Domains\DomainResponder;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\DomainRepository;
use Mailadmin\Runtime\Clock;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

return [
    // ------------------------------------------------------------------
    // The installation-wide default a domain falls back to — the middle step
    // of architecture 3.3's three.
    // ------------------------------------------------------------------
    DomainDefaults::class => static fn (Config $config): DomainDefaults
        => DomainDefaults::fromConfig($config),

    // ------------------------------------------------------------------
    // `domain`, `mailadmin_domain_setting`, and the four tables a rename
    // drags along.
    // ------------------------------------------------------------------
    DomainStore::class => static fn (
        Connection $connection,
        Clock $clock,
    ): DomainRepository => new DomainRepository($connection, $clock),

    // ------------------------------------------------------------------
    // The responder needs the mount prefix so that `Location` is correct in
    // both deployment variants (U-25).
    // ------------------------------------------------------------------
    DomainResponder::class => static fn (
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        ProblemResponseFactory $problems,
        DomainDefaults $defaults,
        LoggerInterface $logger,
        Config $config,
    ): DomainResponder => new DomainResponder(
        $responses,
        $streams,
        $problems,
        $defaults,
        $logger,
        $config->basePath(),
    ),
];

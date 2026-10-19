<?php

declare(strict_types=1);

/**
 * Container definitions for M3 — the ManageSieve client and the Cyrus Sieve
 * adapter. One file per package (U-5).
 *
 * **Everything here is bound explicitly, and the reason is one autowiring trap.**
 * `ManageSieveConnectionPool` takes an `Endpoint`, and `config/di/mailstore.php`
 * already binds `Endpoint::class` to the *IMAP* endpoint. Left to autowiring the
 * Sieve pool would dial port 993 and speak ManageSieve at an IMAP server, which
 * fails in a way that reads like a protocol bug rather than a wiring one. The
 * Sieve endpoint therefore never leaves {@see SieveConfiguration}.
 *
 * **The pool is per request**, as M1's is: the container lives for one request,
 * and a ManageSieve session held past it is a timsieved process nobody is
 * watching.
 *
 * **The double is not wired.** {@see \Mailadmin\Infrastructure\Mailstore\InMemorySieveStore}
 * is bound to nothing; tests construct it. A binding behind an environment
 * switch would let a misconfigured production accept vacation notices that go
 * nowhere.
 */

use Mailadmin\Config\Config;
use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusSieveStore;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\MailstoreConfiguration;
use Mailadmin\Infrastructure\Mailstore\Net\TransportFactory;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveCapabilities;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveConfiguration;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveTrace;
use Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller;
use Psr\Log\LoggerInterface;

return [
    SieveConfiguration::class => static fn (
        Config $config,
        MailstoreConfiguration $mailstore,
    ): SieveConfiguration => SieveConfiguration::fromConfig($config, $mailstore),

    // The same switch as the IMAP transcript, deliberately: one mailstore, one
    // decision. It cannot take effect in production — a ManageSieve transcript
    // records the names of every script and the sizes of their contents.
    SieveTrace::class => static fn (
        LoggerInterface $logger,
        SieveConfiguration $sieve,
        Config $config,
    ): SieveTrace => SieveTrace::forEnvironment(
        $logger,
        $sieve->endpoint->toString(),
        $config->environment(),
        $config->boolOrDefault(ProtocolTrace::ENABLE, false),
    ),

    ManageSieveConnectionPool::class => static fn (
        SieveConfiguration $sieve,
        Credentials $credentials,
        TransportFactory $transports,
        SieveTrace $trace,
    ): ManageSieveConnectionPool => new ManageSieveConnectionPool(
        $sieve->endpoint,
        $credentials,
        $transports,
        $trace,
    ),

    SieveStore::class => static fn (CyrusSieveStore $cyrus): SieveStore => $cyrus,

    SieveCapabilities::class => static fn (
        CyrusSieveStore $cyrus,
    ): SieveCapabilities => $cyrus,

    SieveScriptInstaller::class => static fn (
        SieveStore $scripts,
        SieveRenderer $renderer,
        SieveCapabilities $capabilities,
    ): SieveScriptInstaller => new SieveScriptInstaller($scripts, $renderer, $capabilities),
];

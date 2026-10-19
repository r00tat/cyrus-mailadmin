<?php

declare(strict_types=1);

/**
 * Container definitions for M1 and M2 — the IMAP client and the Cyrus adapter.
 *
 * One file per package (U-5). Two things are worth reading before changing
 * anything here:
 *
 * **The pool is per request.** PHP-DI gives one instance per container, and the
 * container lives for one request, so that is already the lifetime the pool
 * documents. Nothing closes it automatically — the front controller does, in a
 * `finally`, because a connection nobody closes is a Cyrus session that idles
 * until the server's own timeout.
 *
 * **The double is not wired.** {@see \Mailadmin\Infrastructure\Mailstore\InMemoryMailstore}
 * is bound to nothing: tests construct it themselves. Binding it behind an
 * environment switch would mean a misconfigured production could quietly accept
 * mailbox operations that go nowhere.
 */

use Mailadmin\Config\Config;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusMailstore;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\MailstoreConfiguration;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\StreamTransportFactory;
use Mailadmin\Infrastructure\Mailstore\Net\TransportFactory;
use Psr\Log\LoggerInterface;

return [
    MailstoreConfiguration::class => static fn (Config $config): MailstoreConfiguration
        => MailstoreConfiguration::fromConfig($config),

    Endpoint::class => static fn (MailstoreConfiguration $mailstore): Endpoint
        => $mailstore->endpoint,

    Credentials::class => static fn (MailstoreConfiguration $mailstore): Credentials
        => $mailstore->credentials,

    TransportFactory::class => static fn (
        StreamTransportFactory $factory,
    ): TransportFactory => $factory,

    // ------------------------------------------------------------------
    // The transcript. Off unless asked for, and it cannot be switched on in
    // production — see ProtocolTrace for why that is not a courtesy.
    // ------------------------------------------------------------------
    ProtocolTrace::class => static fn (
        LoggerInterface $logger,
        MailstoreConfiguration $mailstore,
        Config $config,
    ): ProtocolTrace => ProtocolTrace::forEnvironment(
        $logger,
        $mailstore->endpoint->toString(),
        $config->environment(),
        $config->boolOrDefault(ProtocolTrace::ENABLE, false),
    ),

    ConnectionPool::class => static fn (
        Endpoint $endpoint,
        Credentials $credentials,
        TransportFactory $transports,
        ProtocolTrace $trace,
    ): ConnectionPool => new ConnectionPool($endpoint, $credentials, $transports, $trace),

    Mailstore::class => static fn (CyrusMailstore $cyrus): Mailstore => $cyrus,
];

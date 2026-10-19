<?php

declare(strict_types=1);

/**
 * Container definitions for S4 — the audit trail.
 *
 * One file per package (U-5). Only the two ports and the request-scoped context
 * are declared; `Redactor`, `ListAuditTrail`, the middleware and the handler are
 * built by autowiring from their constructors.
 *
 * `AuditRequestContext` is listed **because it must be one instance**. PHP-DI
 * shares an autowired entry by default, but an object whose whole purpose is
 * that the middleware and the writer see the same one is not something to leave
 * to a default: two instances would mean every entry recorded without an address
 * or a request id, silently, and nothing would fail.
 *
 * {@see \Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail} is deliberately
 * not wired, for the reason U-37 gives for the mailstore doubles: a container
 * that could be pointed at an in-memory trail by an environment variable would
 * be one variable away from an installation that records nothing. Tests
 * construct it directly.
 */

use Mailadmin\Application\Audit\AuditReader;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Audit\Redactor;
use Mailadmin\Infrastructure\Persistence\AuditRepository;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\SqlAuditWriter;
use Mailadmin\Runtime\Clock;

return [
    AuditRequestContext::class => static fn (): AuditRequestContext => new AuditRequestContext(),

    AuditWriter::class => static fn (
        Connection $connection,
        Redactor $redactor,
        Clock $clock,
        AuditRequestContext $context,
    ): SqlAuditWriter => new SqlAuditWriter($connection, $redactor, $clock, $context),

    AuditReader::class => static fn (Connection $connection): AuditRepository
        => new AuditRepository($connection),
];

<?php

declare(strict_types=1);

/**
 * Container definitions for A3 — addresses, aliases, catchall, forwards and
 * the vacation notice.
 *
 * One file per package (U-5). Only what autowiring cannot resolve is here: the
 * store behind its interface, and the responder, which needs the mount prefix
 * in order to build a correct `Location`. The use cases, their helpers and the
 * fifteen handlers are built from their constructors; the Sieve side reuses
 * M3's `SieveStore` and `SieveScriptInstaller` from `config/di/sieve.php`.
 *
 * **The in-memory store is not wired**, for the reason A1 gives for its own
 * double.
 */

use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Config\Config;
use Mailadmin\Http\Handler\Addresses\AddressResponder;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\VirtualRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

return [
    // ------------------------------------------------------------------
    // `virtual`, and what A3 reads of `accountuser` to classify a row.
    // ------------------------------------------------------------------
    AddressStore::class => static fn (Connection $connection): VirtualRepository
        => new VirtualRepository($connection),

    AddressResponder::class => static fn (
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        ProblemResponseFactory $problems,
        LoggerInterface $logger,
        Config $config,
    ): AddressResponder => new AddressResponder(
        $responses,
        $streams,
        $problems,
        $logger,
        $config->basePath(),
    ),
];

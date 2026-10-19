<?php

declare(strict_types=1);

/**
 * Container definitions for A2 — mail accounts.
 *
 * One file per package (U-5). Only what autowiring cannot resolve is here: the
 * store behind its interface, and the responder, which needs the mount prefix
 * in order to build a correct `Location`. The use cases, `AccountLocator`,
 * `DomainAllowance` and the eight handlers are built from their constructors.
 *
 * The repair queue and `DomainDefaults` are A1's entries and are reused.
 *
 * **The in-memory store is not wired**, for the reason A1 gives for its own
 * double: an installation that could be talked into an in-memory `accountuser`
 * table would look like it was working while doing nothing.
 */

use Mailadmin\Application\Account\AccountStore;
use Mailadmin\Config\Config;
use Mailadmin\Http\Handler\Accounts\AccountResponder;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\AccountRepository;
use Mailadmin\Infrastructure\Persistence\Connection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

return [
    // ------------------------------------------------------------------
    // `accountuser` and the account rows of `virtual`.
    // ------------------------------------------------------------------
    AccountStore::class => static fn (Connection $connection): AccountRepository
        => new AccountRepository($connection),

    // ------------------------------------------------------------------
    // The responder needs the mount prefix so that `Location` is correct in
    // both deployment variants (U-25).
    // ------------------------------------------------------------------
    AccountResponder::class => static fn (
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        ProblemResponseFactory $problems,
        LoggerInterface $logger,
        Config $config,
    ): AccountResponder => new AccountResponder(
        $responses,
        $streams,
        $problems,
        $logger,
        $config->basePath(),
    ),
];

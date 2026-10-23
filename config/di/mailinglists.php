<?php

declare(strict_types=1);

/**
 * Container definitions for A5 — mailing lists.
 *
 * One file per package (U-5), and two entries in it. Everything else A5 needs —
 * the five use cases, the five handlers, the responder — has a constructor
 * PHP-DI can read, so listing them here would only be a second place to keep in
 * step with the classes.
 *
 * **`InMemoryMailingListRepository` is deliberately not wired**, the same way
 * S2's and M2's doubles are not (U-37). A container that could be talked into an
 * in-memory mailing list store by an environment variable would be one variable
 * away from an installation whose saves go nowhere and report success. Tests
 * construct it directly.
 */

use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Config\Config;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\SqlMailingListRepository;

return [
    ListDelivery::class => static fn (Config $config): ListDelivery
        => ListDelivery::fromConfig($config),

    MailingListRepository::class => static fn (Connection $connection): SqlMailingListRepository
        => new SqlMailingListRepository($connection),
];

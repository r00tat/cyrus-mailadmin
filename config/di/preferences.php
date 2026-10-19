<?php

declare(strict_types=1);

/**
 * Container definitions for W2 — per-administrator interface preferences.
 *
 * One file per package (U-5). The two use cases and the two handlers are
 * autowired; only the store behind its interface is declared. The in-memory
 * double is deliberately not wired (U-37).
 */

use Mailadmin\Application\Admin\AdminPreferenceStore;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\SqlAdminPreferenceStore;

return [
    AdminPreferenceStore::class => static fn (Connection $connection): SqlAdminPreferenceStore
        => new SqlAdminPreferenceStore($connection),
];

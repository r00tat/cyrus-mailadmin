<?php

declare(strict_types=1);

/**
 * Container definitions for A4 — administrator management.
 *
 * One file per package (U-5). Only what autowiring cannot resolve is here,
 * which for this package is exactly one thing: the store behind its interface.
 *
 * The six use cases and the six handlers are not listed. PHP-DI builds each of
 * them from its constructor once the entry below exists, and everything else
 * they need — `PasswordPolicy`, `PasswordHasher`, `RefreshTokenStore`, `Clock`,
 * the logger — is already declared by S1, S2 and F1.
 *
 * **The in-memory double is deliberately not wired**, the same way S2's
 * administrator directory and M2's mailstore are not (U-37). A container that
 * could be talked into an in-memory `adminuser` table by an environment variable
 * would be one variable away from an installation where deleting an
 * administrator silently does nothing. Tests construct it directly.
 */

use Mailadmin\Application\Admin\AdminStore;
use Mailadmin\Infrastructure\Persistence\AdminRepository;
use Mailadmin\Infrastructure\Persistence\Connection;

return [
    AdminStore::class => static fn (Connection $connection): AdminRepository
        => new AdminRepository($connection),
];

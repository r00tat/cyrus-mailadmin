<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use DateTimeImmutable;

/**
 * Where an administrator's interface preferences live:
 * `mailadmin_admin_setting`, one row per key (migration 0050, U-90).
 *
 * A port of its own rather than three more methods on {@see AdminStore}: A4's
 * store is about the legacy `adminuser` world and its MyISAM caveats, this one
 * is a `mailadmin_` table that only W2 reads. Deleting an administrator still
 * clears these rows — that cascade is A4's and stays in `AdminRepository`.
 */
interface AdminPreferenceStore
{
    /**
     * Never fails for an administrator without rows: that is the normal case
     * for everyone who has not chosen anything, and for every administrator
     * webcyradm created.
     */
    public function find(string $username): AdminPreferences;

    /**
     * Stores the whole set. A member that is `null` removes its row — „nothing
     * chosen" is the absence of a row, the same convention A1 uses for
     * `mailadmin_domain_setting`.
     */
    public function save(string $username, AdminPreferences $preferences, DateTimeImmutable $at): void;

    /**
     * Runs `$work` in one transaction with the audit entry (U-63).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed;
}

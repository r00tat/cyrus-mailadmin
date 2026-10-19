<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * Which repair-queue entries the application can finish itself, and what each
 * needs to do it (A6 open point 5, U-118).
 *
 * The decision is per **step**, not per operation: `mailstore.delete_mailbox`
 * means the same thing after an abandoned creation as after a deletion — a
 * mailbox no account claims — and is retried the same way.
 *
 * | step | retry | precondition checked first |
 * |---|---|---|
 * | `mailstore.default_folders` | create and subscribe the folders | row and mailbox exist |
 * | `mailstore.delete_mailbox` | delete the mailbox | no row, alias or sender claims the name (under the locks of U-97) |
 * | `mailstore.restore_quota` | set the limit back | the limit is still the one the entry left |
 * | `mailstore.rename_back` | rename the mailbox back | database still has the old name, mailbox only under the new one |
 *
 * Everything else is manual: `mailstore.create_mailbox_uncertain` (nobody knows
 * whose mailbox it is), `database.remove_rows` and `virtual.restore` (the rows
 * may be half changed in ways only a human can judge), `audit.record` (a missing
 * audit entry cannot be written after the fact without inventing it) and
 * `sieve.restore` (the previous script is not in the entry).
 *
 * An entry whose step is retryable but lacks a parameter — one recorded by the
 * log-only stopgap, or written by hand — is manual as well.
 */
final class RepairCatalogue
{
    public const string DEFAULT_FOLDERS = 'mailstore.default_folders';
    public const string DELETE_MAILBOX = 'mailstore.delete_mailbox';
    public const string RESTORE_QUOTA = 'mailstore.restore_quota';
    public const string RENAME_BACK = 'mailstore.rename_back';

    /**
     * Step => the parameters a retry reads. `limit_kib` and `current_kib` may be
     * empty (no limit), so they are required to be present, not non-empty.
     */
    private const array REQUIRED = [
        self::DEFAULT_FOLDERS => ['account', 'domain', 'folders'],
        self::DELETE_MAILBOX => ['account', 'domain'],
        self::RESTORE_QUOTA => ['account', 'domain', 'limit_kib', 'current_kib'],
        self::RENAME_BACK => ['account', 'domain', 'target_account', 'target_domain'],
    ];

    /** Parameters that may legitimately be the empty string. */
    private const array MAY_BE_EMPTY = ['limit_kib', 'current_kib'];

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $parameters
     */
    public static function isRetryable(string $step, array $parameters): bool
    {
        $required = self::REQUIRED[$step] ?? null;

        if ($required === null) {
            return false;
        }

        foreach ($required as $name) {
            if (!array_key_exists($name, $parameters)) {
                return false;
            }

            if ($parameters[$name] === '' && !in_array($name, self::MAY_BE_EMPTY, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function retryableSteps(): array
    {
        return array_keys(self::REQUIRED);
    }
}

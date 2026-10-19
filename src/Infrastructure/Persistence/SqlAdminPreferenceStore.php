<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Application\Admin\AdminPreferences;
use Mailadmin\Application\Admin\AdminPreferenceStore;
use Mailadmin\Domain\Model\InterfaceLocale;

/**
 * {@see AdminPreferenceStore} on `mailadmin_admin_setting` (migration 0050).
 *
 * One row per key, as 0050 laid out. No new column and no new migration: the
 * table already has the shape a preference needs, and `ui.locale` is the very
 * example its comment names (U-90).
 *
 * ## Absence is „nothing chosen"
 *
 * Choosing „follow the browser" deletes the row rather than writing `NULL`. For
 * a language the two would mean the same thing, and A1's
 * `mailadmin_domain_setting` already settled the convention: a table that holds
 * only what somebody actually said can be read at a glance. A `NULL` row written
 * by hand is read the same way, as is a value this build does not offer
 * ({@see InterfaceLocale::fromStored()}).
 */
final readonly class SqlAdminPreferenceStore implements AdminPreferenceStore
{
    private const string TABLE = 'mailadmin_admin_setting';

    public function __construct(private Connection $connection)
    {
    }

    public function find(string $username): AdminPreferences
    {
        /** @var mixed $value */
        $value = $this->connection->selectValue(
            'SELECT `value` FROM `' . self::TABLE . '` WHERE `username` = :username AND `name` = :name',
            ['username' => $username, 'name' => AdminPreferences::LOCALE],
        );

        return new AdminPreferences(InterfaceLocale::fromStored(is_string($value) ? $value : null));
    }

    public function save(string $username, AdminPreferences $preferences, DateTimeImmutable $at): void
    {
        if ($preferences->locale === null) {
            $this->connection->execute(
                'DELETE FROM `' . self::TABLE . '` WHERE `username` = :username AND `name` = :name',
                ['username' => $username, 'name' => AdminPreferences::LOCALE],
            );

            return;
        }

        // Native prepares (EMULATE_PREPARES = false) refuse a named placeholder
        // used twice, hence VALUES() in the update clause.
        $this->connection->execute(
            'INSERT INTO `' . self::TABLE . '` (`username`, `name`, `value`, `updated_at`) '
            . 'VALUES (:username, :name, :value, :updated) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
            [
                'username' => $username,
                'name' => AdminPreferences::LOCALE,
                'value' => $preferences->locale->value,
                'updated' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function atomically(callable $work): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $work());
    }
}

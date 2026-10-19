<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Application\Admin\AdminPreferences;
use Mailadmin\Domain\Model\InterfaceLocale;
use Mailadmin\Infrastructure\Persistence\SqlAdminPreferenceStore;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * {@see SqlAdminPreferenceStore} against a real MariaDB: the upsert under native
 * prepares, the row-per-key shape of migration 0050, and that a `NULL` or an
 * unknown value written by somebody else reads as „nothing chosen".
 */
#[CoversClass(SqlAdminPreferenceStore::class)]
final class AdminPreferenceStoreIntegrationTest extends DatabaseTestCase
{
    private SqlAdminPreferenceStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropIfPresent('mailadmin_admin_setting');
        $this->willCreate('mailadmin_admin_setting');

        // Migration 0050, applied by hand, as AdminRepositoryIntegrationTest does.
        $this->pdo->exec(
            'CREATE TABLE `mailadmin_admin_setting` (
                `username`   VARCHAR(190) NOT NULL,
                `name`       VARCHAR(190) NOT NULL,
                `value`      TEXT         NULL,
                `updated_at` DATETIME     NOT NULL,
                PRIMARY KEY (`username`, `name`),
                KEY `mailadmin_admin_setting_name` (`name`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci',
        );

        $this->store = new SqlAdminPreferenceStore($this->connection);
    }

    public function testNoRowIsNothingChosen(): void
    {
        self::assertNull($this->store->find('root')->locale);
    }

    public function testSavingTwiceUpdatesTheOneRow(): void
    {
        $first = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC'));
        $second = new DateTimeImmutable('2026-01-02 12:00:00+02:00');

        $this->store->save('root', new AdminPreferences(InterfaceLocale::German), $first);
        $this->store->save('root', new AdminPreferences(InterfaceLocale::German), $second);

        self::assertSame(InterfaceLocale::German, $this->store->find('root')->locale);

        $rows = $this->connection->select(
            'SELECT `name`, `value`, `updated_at` FROM `mailadmin_admin_setting` WHERE `username` = :u',
            ['u' => 'root'],
        );

        // Stored in UTC (U-14), whatever zone the clock handed in.
        self::assertSame([['name' => 'ui.locale', 'value' => 'de', 'updated_at' => '2026-01-02 10:00:00']], $rows);
    }

    public function testNothingChosenRemovesTheRowAndOnlyThatRow(): void
    {
        $at = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC'));

        $this->store->save('root', new AdminPreferences(InterfaceLocale::German), $at);
        $this->store->save('other', new AdminPreferences(InterfaceLocale::German), $at);
        $this->connection->execute(
            'INSERT INTO `mailadmin_admin_setting` (`username`, `name`, `value`, `updated_at`) '
            . "VALUES ('root', 'ui.something_else', 'x', '2026-01-01 00:00:00')",
        );

        $this->store->save('root', AdminPreferences::none(), $at);

        self::assertNull($this->store->find('root')->locale);
        self::assertSame(InterfaceLocale::German, $this->store->find('other')->locale);
        self::assertSame(1, (int) $this->connection->selectValue(
            "SELECT COUNT(*) FROM `mailadmin_admin_setting` WHERE `username` = 'root'",
        ));
    }

    public function testANullOrUnknownValueWrittenElsewhereReadsAsNothingChosen(): void
    {
        $this->connection->execute(
            'INSERT INTO `mailadmin_admin_setting` (`username`, `name`, `value`, `updated_at`) '
            . "VALUES ('a', 'ui.locale', NULL, '2026-01-01 00:00:00'), "
            . "('b', 'ui.locale', 'xx', '2026-01-01 00:00:00')",
        );

        self::assertNull($this->store->find('a')->locale);
        self::assertNull($this->store->find('b')->locale);
    }
}

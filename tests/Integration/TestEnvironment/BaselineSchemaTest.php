<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The database really is the webcyradm schema, and the two views really work.
 *
 * The environment exists so that the migrator is tested against a legacy schema
 * rather than an empty one (architecture 12.3). If the baseline quietly stopped
 * being a baseline, every migration test would still pass and prove nothing.
 * These tests are what keeps that from happening.
 *
 * Note what is NOT asserted: that no `mailadmin_` table exists. Running the
 * migrator is the point of the environment, so after the first migration run
 * they are there and should be. The baseline lives in
 * `docker/db/init/00-baseline-schema.sql` and is restored by
 * `bin/test-env reset`.
 */
final class BaselineSchemaTest extends TestCase
{
    use RequiresTestEnvironment;

    /**
     * Every table the analysis found in production, including the dead ones.
     *
     * @return iterable<string, array{string}>
     */
    public static function legacyTableProvider(): iterable
    {
        $tables = [
            'accountuser', 'adminuser', 'alias', 'domain', 'domainadmin', 'groups', 'lists',
            'log', 'search', 'sentmails', 'settings', 'users', 'virtual',
        ];

        foreach ($tables as $table) {
            yield $table => [$table];
        }
    }

    #[DataProvider('legacyTableProvider')]
    public function testTheLegacyTableExists(string $table): void
    {
        $this->requireTestEnvironment();

        self::assertContains($table, $this->tableNames(), sprintf(
            'The baseline is missing the table `%s`. The migrator would be tested against '
            . 'something production does not look like.',
            $table,
        ));
    }

    /**
     * The two views are what Postfix and pam_mysql actually read. They are
     * created by Salt in production, so a migrator must never assume it owns
     * them (E-1).
     */
    public function testBothViewsExistAndAreViews(): void
    {
        $this->requireTestEnvironment();

        $statement = TestEnvironment::connection()->query(
            'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()',
        );

        self::assertNotFalse($statement);

        $views = $statement->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('allusers', $views);
        self::assertContains('validsenders', $views);
    }

    /**
     * The engine and the character set. Both are inferred rather than measured
     * (see the header of the baseline file) — pinning them means a change is a
     * decision instead of a drift.
     */
    public function testTheLegacyTablesAreMyIsamAndLatin1(): void
    {
        $this->requireTestEnvironment();

        $statement = TestEnvironment::connection()->query(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN '
            . "('accountuser', 'domain', 'virtual', 'adminuser')",
        );

        self::assertNotFalse($statement);

        $rows = $statement->fetchAll();
        self::assertCount(4, $rows);

        foreach ($rows as $row) {
            self::assertSame('MyISAM', $row['ENGINE'], sprintf(
                '`%s` is not MyISAM any more.',
                $row['TABLE_NAME'],
            ));
            self::assertStringStartsWith('latin1', (string) $row['TABLE_COLLATION'], sprintf(
                '`%s` is no longer latin1. A utf8mb4 legacy table cannot exist with these keys — '
                . 'see docker/db/init/00-baseline-schema.sql.',
                $row['TABLE_NAME'],
            ));
        }
    }

    /**
     * The `REPLACE` fragility from architecture 3.3, reproduced rather than
     * argued about.
     *
     * For an account whose local part contains the domain name, the view
     * reports the wrong address. We do not fix the view (E-1); the application
     * reads `accountuser` and builds the address from (local part, domain,
     * scheme). The test pins the behaviour so nobody "fixes" it by accident and
     * breaks pam_mysql for everyone else.
     */
    public function testTheAllusersViewMisreportsAnAccountThatContainsItsOwnDomain(): void
    {
        $this->requireFixtures();

        $statement = TestEnvironment::connection()->query(
            'SELECT username FROM allusers WHERE password = '
            . "(SELECT password FROM accountuser WHERE username = 'news.example.test.example.test')",
        );

        self::assertNotFalse($statement);

        $spellings = $statement->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('news.example.test.example.test', $spellings, 'The stored name is gone.');
        self::assertContains(
            'news@example.test',
            $spellings,
            'The view no longer produces the mangled address. Either the view was changed — which '
            . 'E-1 forbids — or the fixture no longer exercises the case.',
        );
        self::assertNotContains(
            'news.example.test@example.test',
            $spellings,
            'The view now produces the correct address. That would be good news, but it means the '
            . 'view was rewritten, and Salt owns it.',
        );
    }

    /**
     * `validsenders` ties an address to the logins allowed to use it — both
     * spellings of each login, which is why the row count is roughly twice the
     * number of `virtual` rows that name an account.
     */
    public function testValidsendersTiesBothSpellingsToAnAddress(): void
    {
        $this->requireFixtures();

        $statement = TestEnvironment::connection()->prepare(
            'SELECT username FROM validsenders WHERE alias = :alias ORDER BY username',
        );
        $statement->execute(['alias' => 'anna@example.test']);

        $logins = $statement->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('anna.example.test', $logins);
        self::assertContains('anna@example.test', $logins);
    }

    /**
     * The application user may not DROP. Rule one in CLAUDE.md says migrations
     * are additive; withholding the privilege is what makes that enforceable
     * rather than merely agreed.
     */
    public function testTheApplicationUserCannotDropATable(): void
    {
        $this->requireTestEnvironment();

        $connection = TestEnvironment::connection();
        $connection->exec('CREATE TABLE IF NOT EXISTS mailadmin_privilege_probe (id INT)');

        try {
            $connection->exec('DROP TABLE mailadmin_privilege_probe');
            self::fail(
                'The application user was allowed to DROP a table. docker/db/init/20-grants.sql '
                . 'withholds that privilege on purpose (CLAUDE.md, rule one).',
            );
        } catch (\PDOException $exception) {
            self::assertStringContainsString('denied', strtolower($exception->getMessage()));
        }
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $statement = TestEnvironment::connection()->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
        );

        self::assertNotFalse($statement);

        /** @var list<string> $names */
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }
}

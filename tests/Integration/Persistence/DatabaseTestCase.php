<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ConnectionFactory;
use Mailadmin\Infrastructure\Persistence\DatabaseConfiguration;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base for the tests that need a real MariaDB.
 *
 * **They skip when there is none. They never pass when there is none.** A test
 * that silently reports success against a database it never reached is worse
 * than no test: it makes the suite look like it covers the migrator when it
 * covers nothing.
 *
 * ```sh
 * MAILADMIN_TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=mailadmin_test' \
 * MAILADMIN_TEST_DB_USER=root \
 * MAILADMIN_TEST_DB_PASSWORD=secret \
 *   vendor/bin/phpunit --testsuite integration
 * ```
 *
 * F5 provides the container these variables will point at.
 *
 * ## The safety catch
 *
 * These tests create and drop tables. Pointed at the production database by
 * accident, they would drop `domain` and `accountuser`. So before anything runs,
 * {@see assertDisposableDatabase()} checks that the legacy tables are either
 * absent or empty, and **skips** otherwise. A database with real rows in it is
 * never touched.
 *
 * ## Why there is a second DSN
 *
 * That catch also fires against F5, because F5's database is *supposed* to hold
 * fixtures — every other integration test needs them. Two suites, two
 * requirements, one database: something had to give, and it was not going to be
 * the safety catch.
 *
 * So {@see DISPOSABLE_DSN} takes precedence when it is set (U-36). F5 points it
 * at an empty second database, and both suites run in one command. Everywhere
 * else nothing changes: without it, {@see DSN} is used exactly as before.
 */
abstract class DatabaseTestCase extends TestCase
{
    public const string DSN = 'MAILADMIN_TEST_DB_DSN';
    public const string USER = 'MAILADMIN_TEST_DB_USER';
    public const string PASSWORD = 'MAILADMIN_TEST_DB_PASSWORD';

    /**
     * An empty database these tests may create and drop tables in, used instead
     * of {@see DSN} when it is set. The credentials stay {@see USER} and
     * {@see PASSWORD}: it is the same server, a different schema.
     */
    public const string DISPOSABLE_DSN = 'MAILADMIN_TEST_DB_DISPOSABLE_DSN';

    protected Connection $connection;

    protected PDO $pdo;

    /** @var list<string> tables this test created, dropped again in tearDown */
    private array $created = [];

    protected function setUp(): void
    {
        $dsn = self::env(self::DISPOSABLE_DSN) ?? self::env(self::DSN);

        if ($dsn === null) {
            self::markTestSkipped(sprintf(
                'No database: set %s or %s (and %s / %s) to run the persistence integration tests.',
                self::DISPOSABLE_DSN,
                self::DSN,
                self::USER,
                self::PASSWORD,
            ));
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('No database: ext-pdo_mysql is not installed.');
        }

        try {
            $configuration = DatabaseConfiguration::create(
                dsn: $dsn,
                user: self::env(self::USER) ?? 'root',
                password: self::env(self::PASSWORD) ?? '',
                timeoutSeconds: 3,
            );

            $this->connection = new Connection(new ConnectionFactory($configuration));
            $this->pdo = $this->connection->pdo();
        } catch (PersistenceError $error) {
            self::markTestSkipped('No database: ' . $error->getMessage());
        }

        $this->assertDisposableDatabase();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $table) {
            try {
                $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            } catch (Throwable) {
                // A leftover table is a nuisance in a throwaway database, not a
                // reason to fail the test that just passed.
            }
        }

        $this->created = [];
    }

    /**
     * Remember a table so that {@see tearDown()} removes it again.
     */
    protected function willCreate(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->created[] = $table;
        }
    }

    protected function dropIfPresent(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    protected function tableExists(string $name): bool
    {
        return (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => $name],
        ) > 0;
    }

    /**
     * @return array{engine: string, collation: string}
     */
    protected function tableInfo(string $name): array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->connection->selectOne(
            'SELECT engine, table_collation FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => $name],
        );

        return [
            'engine' => is_scalar($row['engine'] ?? null) ? (string) $row['engine'] : '',
            'collation' => is_scalar($row['table_collation'] ?? null)
                ? (string) $row['table_collation']
                : '',
        ];
    }

    /**
     * The legacy tables of a **webcyradm database at its starting state** —
     * F3's second integration test case asks for exactly this rather than an
     * empty schema.
     *
     * The definitions follow webcyradm's own `scripts/create_mysql.sql`, brought
     * up to a syntax MariaDB still accepts (`TYPE=MyISAM` was removed years ago).
     * Every value in them is invented; `example.test` is reserved for this.
     */
    protected function createLegacyBaseline(): void
    {
        $this->dropIfPresent('accountuser', 'adminuser', 'domain', 'domainadmin', 'virtual', 'settings', 'lists');
        $this->willCreate('accountuser', 'adminuser', 'domain', 'domainadmin', 'virtual', 'settings', 'lists');

        $this->pdo->exec(
            'CREATE TABLE `domain` (
                domain_name  varchar(255) NOT NULL DEFAULT "",
                prefix       varchar(50)  NOT NULL DEFAULT "",
                maxaccounts  int(11)      NOT NULL DEFAULT 20,
                quota        int(10)      NOT NULL DEFAULT 20000,
                domainquota  int(10)      NOT NULL DEFAULT 0,
                transport    varchar(255) NOT NULL DEFAULT "cyrus",
                freenames    enum("YES","NO") NOT NULL DEFAULT "NO",
                freeaddress  enum("YES","NO") NOT NULL DEFAULT "NO",
                folders      varchar(255) NOT NULL DEFAULT "",
                PRIMARY KEY (domain_name),
                UNIQUE KEY prefix (prefix)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1',
        );

        $this->pdo->exec(
            'CREATE TABLE `accountuser` (
                username     varchar(255) BINARY NOT NULL DEFAULT "",
                password     varchar(50)  BINARY NOT NULL DEFAULT "",
                prefix       varchar(50)  NOT NULL DEFAULT "",
                domain_name  varchar(255) NOT NULL DEFAULT "",
                imap         int(10) NOT NULL DEFAULT 1,
                pop          int(10) NOT NULL DEFAULT 1,
                sieve        int(10) NOT NULL DEFAULT 1,
                smtpauth     int(10) NOT NULL DEFAULT 1,
                UNIQUE KEY username (username)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1',
        );

        $this->pdo->exec(
            'CREATE TABLE `virtual` (
                alias    varchar(255) NOT NULL DEFAULT "",
                dest     longtext,
                username varchar(50)  NOT NULL DEFAULT "",
                status   int(11)      NOT NULL DEFAULT 1,
                KEY alias (alias)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1',
        );

        $this->pdo->exec(
            'CREATE TABLE `adminuser` (
                username varchar(50) BINARY NOT NULL DEFAULT "",
                password varchar(50) BINARY NOT NULL DEFAULT "",
                type     int(11) NOT NULL DEFAULT 0,
                SID      varchar(255) NOT NULL DEFAULT "",
                home     varchar(255) NOT NULL DEFAULT "",
                PRIMARY KEY (username)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1',
        );

        $this->pdo->exec(
            'CREATE TABLE `domainadmin` (
                domain_name varchar(255) NOT NULL DEFAULT "",
                adminuser   varchar(255) NOT NULL DEFAULT ""
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1',
        );

        $this->pdo->exec(
            'CREATE TABLE `settings` (
                username   varchar(50) BINARY NOT NULL DEFAULT "",
                style      varchar(50) NOT NULL DEFAULT "default",
                maxdisplay int(4) NOT NULL DEFAULT 15,
                warnlevel  int(3) NOT NULL DEFAULT 90,
                PRIMARY KEY (username)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1',
        );

        // The production DDL (`docker/db/init/00-baseline-schema.sql`). Tests
        // that need other rows drop and recreate it themselves.
        $this->pdo->exec(
            'CREATE TABLE `lists` (
                `id` int(4) unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `tag` varchar(255) NOT NULL,
                `address` varchar(255) NOT NULL,
                `members` text NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci',
        );

        // A handful of synthetic rows, so that "the tables are not empty" is part
        // of the starting state the migrator meets.
        $this->pdo->exec(
            'INSERT INTO `domain` (domain_name, prefix, folders) '
            . 'VALUES ("example.test", "exam", "Sent,Trash")',
        );
        $this->pdo->exec(
            'INSERT INTO `accountuser` (username, password, prefix, domain_name) '
            . 'VALUES ("info.example.test", "$2y$10$notarealhashnotarealhashno", "exam", "example.test")',
        );
        $this->pdo->exec(
            'INSERT INTO `virtual` (alias, dest, username) '
            . 'VALUES ("info@example.test", "info.example.test", "info.example.test")',
        );
    }

    /**
     * Refuse to work on anything that might be real.
     */
    private function assertDisposableDatabase(): void
    {
        foreach (['accountuser', 'domain', 'adminuser'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $rows = (int) $this->connection->selectValue(
                sprintf('SELECT COUNT(*) FROM `%s`', $table),
            );

            // The baseline this suite creates has one or two rows; anything with
            // more is somebody's data.
            if ($rows > 5) {
                self::markTestSkipped(sprintf(
                    'Refusing to run: the database behind %s has %d rows in `%s` and looks like a '
                    . 'real installation. These tests create and drop tables — point them at a '
                    . 'throwaway database.',
                    self::DSN,
                    $rows,
                    $table,
                ));
            }
        }
    }

    protected static function env(string $name): ?string
    {
        /** @var mixed $value */
        $value = $_ENV[$name] ?? getenv($name);

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}

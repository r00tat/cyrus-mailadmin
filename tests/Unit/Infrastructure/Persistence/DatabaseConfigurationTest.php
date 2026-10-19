<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence;

use Mailadmin\Config\Config;
use Mailadmin\Infrastructure\Persistence\DatabaseConfiguration;
use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseConfiguration::class)]
final class DatabaseConfigurationTest extends TestCase
{
    public function testAppendsTheCharsetWhenTheDsnHasNone(): void
    {
        $configuration = DatabaseConfiguration::create('mysql:host=db;dbname=mail', 'app');

        self::assertSame('mysql:host=db;dbname=mail;charset=utf8mb4', $configuration->dsn);
    }

    public function testReplacesAnotherCharsetRatherThanAcceptingIt(): void
    {
        $configuration = DatabaseConfiguration::create(
            'mysql:host=db;charset=latin1;dbname=mail',
            'app',
        );

        self::assertStringContainsString('charset=utf8mb4', $configuration->dsn);
        self::assertStringNotContainsString('latin1', $configuration->dsn);
    }

    public function testRejectsANonMysqlDsn(): void
    {
        $this->expectException(PersistenceError::class);

        DatabaseConfiguration::create('pgsql:host=db;dbname=mail', 'app');
    }

    public function testTheRedactedDsnKeepsNoPassword(): void
    {
        $configuration = DatabaseConfiguration::create(
            'mysql:host=db;dbname=mail;password=hunter2',
            'app',
            'hunter2',
        );

        self::assertStringNotContainsString('hunter2', $configuration->redactedDsn());
        self::assertStringContainsString('password=<redacted>', $configuration->redactedDsn());
    }

    public function testThePasswordIsOnlyReachableThroughItsAccessor(): void
    {
        $configuration = DatabaseConfiguration::create('mysql:host=db;dbname=mail', 'app', 's3cret');

        self::assertSame('s3cret', $configuration->password());
        // print_r() and var_export() of the object would show it; what must not
        // happen is that a *message* carries it.
        self::assertStringNotContainsString('s3cret', $configuration->redactedDsn());
    }

    public function testReadsTheDatabaseNameForTheLockScope(): void
    {
        self::assertSame(
            'mail',
            DatabaseConfiguration::create('mysql:host=db;port=3306;dbname=mail', 'app')
                ->databaseName(),
        );

        self::assertNull(
            DatabaseConfiguration::create('mysql:unix_socket=/tmp/mysql.sock', 'app')
                ->databaseName(),
        );
    }

    public function testAMissingDsnNamesTheVariable(): void
    {
        $config = Config::fromArray([]);

        try {
            DatabaseConfiguration::fromConfig($config);
            self::fail('Expected a PersistenceError.');
        } catch (PersistenceError $error) {
            self::assertSame(ErrorCode::CONFIGURATION_INVALID, $error->errorCode());
            self::assertStringContainsString(DatabaseConfiguration::DSN, $error->getMessage());
        }
    }

    public function testAMissingUserNamesTheVariable(): void
    {
        $config = Config::fromArray([
            DatabaseConfiguration::DSN => 'mysql:host=db;dbname=mail',
        ]);

        $this->expectExceptionMessageMatches('/' . DatabaseConfiguration::USER . '/');

        DatabaseConfiguration::fromConfig($config);
    }

    public function testReadsTheOptionalTimeout(): void
    {
        $configuration = DatabaseConfiguration::fromConfig(Config::fromArray([
            DatabaseConfiguration::DSN => 'mysql:host=db;dbname=mail',
            DatabaseConfiguration::USER => 'app',
            DatabaseConfiguration::TIMEOUT => '9',
        ]));

        self::assertSame(9, $configuration->timeoutSeconds);
    }

    public function testRejectsAnUnusableTimeout(): void
    {
        $this->expectException(PersistenceError::class);

        DatabaseConfiguration::create('mysql:host=db;dbname=mail', 'app', '', 0);
    }
}

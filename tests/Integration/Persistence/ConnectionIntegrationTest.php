<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ConnectionFactory;
use Mailadmin\Infrastructure\Persistence\DatabaseConfiguration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The four settings from architecture 10.1, verified against a real server
 * rather than against the array they were passed in.
 *
 * Skips without {@see DatabaseTestCase::DSN}.
 */
#[CoversClass(ConnectionFactory::class)]
#[CoversClass(Connection::class)]
final class ConnectionIntegrationTest extends DatabaseTestCase
{
    public function testPreparesAreNotEmulated(): void
    {
        self::assertFalse((bool) $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function testErrorsAreExceptions(): void
    {
        self::assertSame(
            PDO::ERRMODE_EXCEPTION,
            $this->pdo->getAttribute(PDO::ATTR_ERRMODE),
        );
    }

    /**
     * U-14. Without this, `NOW()` follows the server's zone and every timestamp
     * this application writes would depend on where the server thinks it is.
     */
    public function testTheSessionRunsInUtc(): void
    {
        self::assertSame(
            '+00:00',
            $this->connection->selectValue('SELECT @@session.time_zone'),
        );
    }

    public function testTheConnectionSpeaksUtf8mb4(): void
    {
        self::assertSame(
            'utf8mb4',
            $this->connection->selectValue('SELECT @@session.character_set_client'),
        );
    }

    /**
     * A four-byte character survives the round trip. This is what the charset is
     * actually for; `utf8mb3` would truncate or reject it.
     */
    public function testAFourByteCharacterSurvives(): void
    {
        $this->dropIfPresent('mailadmin_charset_probe');
        $this->willCreate('mailadmin_charset_probe');

        $this->pdo->exec(
            'CREATE TABLE `mailadmin_charset_probe` (v VARCHAR(16) NOT NULL) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $this->connection->execute(
            'INSERT INTO mailadmin_charset_probe (v) VALUES (:v)',
            ['v' => "\u{1F4E7}"],
        );

        self::assertSame(
            "\u{1F4E7}",
            $this->connection->selectValue('SELECT v FROM mailadmin_charset_probe'),
        );
    }

    public function testAnIntegerColumnArrivesAsAnInteger(): void
    {
        self::assertSame(1, $this->connection->selectValue('SELECT 1'));
    }

    public function testAnUnreachableServerBecomesAPersistenceErrorWithoutTheCredentials(): void
    {
        $configuration = DatabaseConfiguration::create(
            // Port 1 is reserved and nothing listens there.
            dsn: 'mysql:host=127.0.0.1;port=1;dbname=nowhere',
            user: 'nobody',
            password: 'a-password-that-must-not-appear',
            timeoutSeconds: 1,
        );

        try {
            (new ConnectionFactory($configuration))->connect();
            self::fail('Expected the connection to fail.');
        } catch (\Mailadmin\Infrastructure\Persistence\PersistenceError $error) {
            self::assertStringNotContainsString(
                'a-password-that-must-not-appear',
                $error->getMessage(),
            );
        }
    }

    public function testATransactionOnAnInnodbTableCommitsAndRollsBack(): void
    {
        $this->dropIfPresent('mailadmin_tx_probe');
        $this->willCreate('mailadmin_tx_probe');

        $this->pdo->exec(
            'CREATE TABLE `mailadmin_tx_probe` (id INT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB',
        );

        $this->connection->transactional(function (Connection $connection): void {
            $connection->execute('INSERT INTO mailadmin_tx_probe (id) VALUES (1)');
        });

        try {
            $this->connection->transactional(function (Connection $connection): void {
                $connection->execute('INSERT INTO mailadmin_tx_probe (id) VALUES (2)');

                throw new \RuntimeException('no');
            });
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(
            1,
            (int) $this->connection->selectValue('SELECT COUNT(*) FROM mailadmin_tx_probe'),
        );
    }
}

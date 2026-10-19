<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence;

use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\OpenConnection;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The connection wrapper, exercised against an in-memory SQLite database.
 *
 * Everything asserted here is portable SQL and portable PDO behaviour: binding,
 * transaction nesting, error wrapping. The MySQL-specific parts — the session
 * time zone, `utf8mb4`, `ATTR_EMULATE_PREPARES` on a real server — belong to
 * {@see \Mailadmin\Tests\Integration\Persistence\ConnectionIntegrationTest},
 * which skips unless MAILADMIN_TEST_DB_DSN points at a reachable database.
 */
#[CoversClass(Connection::class)]
#[CoversClass(OpenConnection::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('CREATE TABLE widget (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $this->connection = new Connection(new OpenConnection($pdo, 'sqlite in memory'));
    }

    public function testSelectReturnsRows(): void
    {
        $this->connection->execute(
            'INSERT INTO widget (id, name) VALUES (:id, :name)',
            ['id' => 1, 'name' => 'first'],
        );

        self::assertSame(
            [['id' => 1, 'name' => 'first']],
            $this->connection->select('SELECT id, name FROM widget'),
        );
    }

    public function testSelectOneReturnsNullRatherThanFalse(): void
    {
        self::assertNull($this->connection->selectOne('SELECT id FROM widget WHERE id = :id', ['id' => 9]));
    }

    public function testSelectValueReturnsTheFirstColumn(): void
    {
        self::assertSame(0, (int) $this->connection->selectValue('SELECT COUNT(*) FROM widget'));
    }

    public function testPositionalParametersAreSupportedToo(): void
    {
        $this->connection->execute('INSERT INTO widget (id, name) VALUES (?, ?)', [7, 'seven']);

        self::assertSame(
            'seven',
            $this->connection->selectValue('SELECT name FROM widget WHERE id = ?', [7]),
        );
    }

    /**
     * The whole point of prepared statements: the value is data, never syntax.
     */
    public function testAValueThatLooksLikeSqlIsStoredNotExecuted(): void
    {
        $payload = "x'); DROP TABLE widget; --";

        $this->connection->execute(
            'INSERT INTO widget (id, name) VALUES (:id, :name)',
            ['id' => 1, 'name' => $payload],
        );

        self::assertSame(
            $payload,
            $this->connection->selectValue('SELECT name FROM widget WHERE id = 1'),
        );
        self::assertSame(1, (int) $this->connection->selectValue('SELECT COUNT(*) FROM widget'));
    }

    public function testTransactionalCommits(): void
    {
        $result = $this->connection->transactional(function (Connection $connection): string {
            $connection->execute('INSERT INTO widget (id, name) VALUES (1, :name)', ['name' => 'a']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, (int) $this->connection->selectValue('SELECT COUNT(*) FROM widget'));
        self::assertFalse($this->connection->inTransaction());
    }

    public function testTransactionalRollsBackAndRethrows(): void
    {
        $caught = null;

        try {
            $this->connection->transactional(function (Connection $connection): void {
                $connection->execute('INSERT INTO widget (id, name) VALUES (1, :name)', ['name' => 'a']);

                throw new RuntimeException('the mailstore said no');
            });
        } catch (RuntimeException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame('the mailstore said no', $caught->getMessage());
        self::assertSame(0, (int) $this->connection->selectValue('SELECT COUNT(*) FROM widget'));
        self::assertFalse($this->connection->inTransaction());
    }

    public function testNestedCallsProduceOneTransaction(): void
    {
        $this->connection->transactional(function (Connection $outer): void {
            $outer->execute('INSERT INTO widget (id, name) VALUES (1, :n)', ['n' => 'a']);

            $outer->transactional(function (Connection $inner): void {
                $inner->execute('INSERT INTO widget (id, name) VALUES (2, :n)', ['n' => 'b']);
                self::assertTrue($inner->inTransaction());
            });

            // Still inside: the inner block does not commit.
            self::assertTrue($outer->inTransaction());
        });

        self::assertSame(2, (int) $this->connection->selectValue('SELECT COUNT(*) FROM widget'));
    }

    /**
     * A caught inner failure must not let the outer block commit half of an
     * operation. The transaction is marked rollback-only, the rollback happens,
     * and the caller is told rather than left believing it worked.
     */
    public function testAnInnerFailureThatIsCaughtStillPreventsTheCommit(): void
    {
        try {
            $this->connection->transactional(function (Connection $outer): void {
                $outer->execute('INSERT INTO widget (id, name) VALUES (1, :n)', ['n' => 'a']);

                try {
                    $outer->transactional(static function (): void {
                        throw new RuntimeException('inner');
                    });
                } catch (RuntimeException) {
                    // "handled"
                }
            });
            self::fail('Expected the outer transaction to refuse the commit.');
        } catch (PersistenceError $error) {
            self::assertSame(ErrorCode::TRANSACTION_MISUSE, $error->errorCode());
        }

        self::assertSame(0, (int) $this->connection->selectValue('SELECT COUNT(*) FROM widget'));
    }

    public function testAFailedStatementBecomesAPersistenceErrorWithoutTheValues(): void
    {
        try {
            $this->connection->execute(
                'INSERT INTO nonexistent (name) VALUES (:name)',
                ['name' => 'a-very-secret-hash'],
            );
            self::fail('Expected a PersistenceError.');
        } catch (PersistenceError $error) {
            self::assertSame(ErrorCode::QUERY_FAILED, $error->errorCode());
            self::assertStringContainsString('INSERT INTO nonexistent', $error->getMessage());
            self::assertStringNotContainsString('a-very-secret-hash', $error->getMessage());
        }
    }

    public function testTheConnectionIsOpenedLazily(): void
    {
        $connection = new Connection(new OpenConnection(new PDO('sqlite::memory:')));

        self::assertFalse($connection->isConnected());

        $connection->pdo();

        self::assertTrue($connection->isConnected());
    }

    public function testItDescribesItselfWithoutACredential(): void
    {
        self::assertSame('sqlite in memory', $this->connection->describe());
    }
}

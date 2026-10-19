<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Guard;

use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\Guard\SchemaGuard;
use Mailadmin\Infrastructure\Persistence\Guard\SchemaOutdated;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLock;
use Mailadmin\Infrastructure\Persistence\Migration\Migrator;
use Mailadmin\Infrastructure\Persistence\Migration\SchemaVersionTable;
use Mailadmin\Infrastructure\Persistence\OpenConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The check the web application performs: is the schema current enough to serve?
 *
 * SQLite in memory, because the comparison is `SELECT version FROM …` against a
 * `glob` and both are portable. Applying migrations is not — the statements are
 * MySQL DDL — so the `MAILADMIN_AUTO_MIGRATE` path is covered by the integration
 * suite, which skips without a database.
 */
#[CoversClass(SchemaGuard::class)]
#[CoversClass(SchemaOutdated::class)]
#[CoversClass(SchemaVersionTable::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class SchemaGuardTest extends TestCase
{
    private PDO $pdo;

    private Connection $connection;

    private string $directory;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->connection = new Connection(new OpenConnection($this->pdo));

        $this->directory = sys_get_temp_dir() . '/mailadmin-guard-' . bin2hex(random_bytes(6));
        mkdir($this->directory);

        file_put_contents(
            $this->directory . '/0001_schema_version.sql',
            'CREATE TABLE IF NOT EXISTS mailadmin_schema_version (version INT);',
        );
        file_put_contents(
            $this->directory . '/0002_setting.sql',
            'CREATE TABLE IF NOT EXISTS mailadmin_setting (name TEXT);',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    /**
     * The first start against a webcyradm database: no version table at all.
     */
    public function testAnUnmigratedDatabaseRefusesService(): void
    {
        try {
            $this->guard()->verify();
            self::fail('Expected the guard to refuse.');
        } catch (SchemaOutdated $error) {
            self::assertSame(ErrorCode::SCHEMA_OUTDATED, $error->errorCode());
            self::assertStringContainsString('has never been migrated', $error->getMessage());
            self::assertStringContainsString('bin/migrate.php', $error->getMessage());
        }
    }

    public function testAPartiallyMigratedDatabaseRefusesServiceAndNamesWhatIsMissing(): void
    {
        $this->createVersionTable([1]);

        try {
            $this->guard()->verify();
            self::fail('Expected the guard to refuse.');
        } catch (SchemaOutdated $error) {
            self::assertStringContainsString('0002', $error->getMessage());
            self::assertStringNotContainsString('never been migrated', $error->getMessage());
        }
    }

    public function testACurrentDatabasePassesTheCheck(): void
    {
        $this->createVersionTable([1, 2]);

        $guard = $this->guard();
        $guard->verify();

        self::assertTrue($guard->isSatisfied());
    }

    public function testIsSatisfiedAnswersWithoutThrowing(): void
    {
        self::assertFalse($this->guard()->isSatisfied());
    }

    /**
     * A database that carries a migration this build does not know is ahead, not
     * behind. Refusing there would mean a rollback of the code could not serve at
     * all, which is the opposite of helpful.
     */
    public function testADatabaseAheadOfThisBuildStillServes(): void
    {
        $this->createVersionTable([1, 2, 30]);

        self::assertTrue($this->guard()->isSatisfied());
    }

    public function testTheResultIsRememberedForTheRestOfTheRequest(): void
    {
        $this->createVersionTable([1, 2]);

        $guard = $this->guard();
        $guard->verify();

        // Drop the table underneath it: the answer must not change mid-request,
        // otherwise two handlers in one request could disagree about it.
        $this->pdo->exec('DROP TABLE mailadmin_schema_version');

        self::assertTrue($guard->isSatisfied());
    }

    /**
     * @param list<int> $versions
     */
    private function createVersionTable(array $versions): void
    {
        $this->pdo->exec('CREATE TABLE mailadmin_schema_version (version INT PRIMARY KEY)');

        foreach ($versions as $version) {
            $this->pdo->exec('INSERT INTO mailadmin_schema_version (version) VALUES (' . $version . ')');
        }
    }

    private function guard(bool $autoMigrate = false): SchemaGuard
    {
        $versions = new SchemaVersionTable($this->connection);
        $loader = new MigrationLoader($this->directory);

        return new SchemaGuard(
            versions: $versions,
            loader: $loader,
            migrator: new Migrator(
                $this->connection,
                $loader,
                $versions,
                MigrationLock::forDatabase($this->connection, null),
            ),
            autoMigrate: $autoMigrate,
        );
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ConnectionFactory;
use Mailadmin\Infrastructure\Persistence\DatabaseConfiguration;
use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\Guard\SchemaGuard;
use Mailadmin\Infrastructure\Persistence\Guard\SchemaOutdated;
use Mailadmin\Infrastructure\Persistence\Migration\Migration;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationError;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLock;
use Mailadmin\Infrastructure\Persistence\Migration\Migrator;
use Mailadmin\Infrastructure\Persistence\Migration\SchemaVersionTable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The migrator against a real MariaDB, starting from a **webcyradm database in
 * its original state** rather than from an empty schema — the case F3 asks for,
 * because an empty schema would not tell us whether the migrations leave the
 * legacy tables alone.
 *
 * Skips without {@see DatabaseTestCase::DSN}. It never passes without one.
 */
#[CoversClass(Migrator::class)]
#[CoversClass(SchemaVersionTable::class)]
#[CoversClass(MigrationLock::class)]
final class MigratorIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyBaseline();

        // Every table any shipped migration creates — the migrator runs all of
        // them, so tearDown has to be able to remove all of them again.
        $ours = [
            'mailadmin_schema_version',
            'mailadmin_setting',
            'mailadmin_refresh_token',
            'mailadmin_token_denylist',
            'mailadmin_login_attempt',
            'mailadmin_audit',
            'mailadmin_domain_setting',
            'mailadmin_repair_queue',
            'mailadmin_admin_setting',
            'mailadmin_account_status',
        ];

        $this->dropIfPresent(...$ours);
        $this->willCreate(...$ours);
    }

    public function testAWebcyradmDatabaseCountsAsUnmigrated(): void
    {
        $state = $this->migrator()->state();

        self::assertTrue($state->isUnmigrated());
        self::assertFalse($state->isUpToDate());
        self::assertNotSame(0, $state->pendingCount());
    }

    public function testTheFirstRunCreatesTheTablesAndRecordsThem(): void
    {
        $applied = $this->migrator()->migrate();

        self::assertSame(
            [
                '0001 schema_version',
                '0002 setting',
                '0003 widen_password_columns',
                '0010 refresh_token',
                '0011 token_denylist',
                '0012 login_attempt',
                '0020 audit',
                '0030 domain_setting',
                '0040 repair_queue',
                '0050 admin_setting',
                '0060 account_status',
            ],
            array_map(static fn (Migration $m): string => $m->label(), $applied),
        );

        self::assertTrue($this->tableExists('mailadmin_schema_version'));
        self::assertTrue($this->tableExists('mailadmin_setting'));

        self::assertSame(
            11,
            (int) $this->connection->selectValue('SELECT COUNT(*) FROM mailadmin_schema_version'),
        );
    }

    public function testTheNewTablesAreInnodbAndUtf8mb4(): void
    {
        $this->migrator()->migrate();

        foreach ([
            'mailadmin_schema_version',
            'mailadmin_setting',
            'mailadmin_refresh_token',
            'mailadmin_token_denylist',
            'mailadmin_login_attempt',
        ] as $table) {
            $info = $this->tableInfo($table);

            self::assertSame('InnoDB', $info['engine'], $table);
            self::assertStringStartsWith('utf8mb4', $info['collation'], $table);
        }
    }

    /**
     * The legacy tables come out of a full migration run **exactly** as they
     * went in — except for the one change U-6's rule enumerates: migration 0003
     * widens the two password columns, because an Argon2id or bcrypt hash does
     * not fit `VARCHAR(50)`.
     *
     * The exception is asserted rather than excluded. A blanket "ignore the
     * password columns" would also pass if a migration had changed their
     * nullability, their default or their collation — and a collation change on
     * a column holding hashes is not something to acquire by accident.
     */
    public function testTheLegacyTablesAreUntouchedApartFromTheWidening(): void
    {
        $before = $this->legacyFingerprint();

        $this->migrator()->migrate();

        $after = $this->legacyFingerprint();

        foreach (['accountuser', 'adminuser'] as $table) {
            self::assertSame(
                'varchar(50)',
                self::columnTypeIn($before, $table, 'password'),
                sprintf('%s.password did not start out narrow.', $table),
            );
            self::assertSame(
                'varchar(255)',
                self::columnTypeIn($after, $table, 'password'),
                sprintf('%s.password was not widened by migration 0003.', $table),
            );
        }

        // Everything else, including the *rest* of both password columns.
        self::assertSame(
            self::withoutPasswordColumnType($before),
            self::withoutPasswordColumnType($after),
        );
    }

    public function testRunningItTwiceChangesNothing(): void
    {
        $this->migrator()->migrate();

        self::assertSame([], $this->migrator()->migrate());
        self::assertTrue($this->migrator()->state()->isUpToDate());
        self::assertSame(
            11,
            (int) $this->connection->selectValue('SELECT COUNT(*) FROM mailadmin_schema_version'),
        );
    }

    /**
     * Every statement has to be repeatable, because a migration that fails
     * halfway is repeated from the beginning — DDL commits implicitly and cannot
     * be rolled back.
     */
    public function testEveryMigrationSurvivesBeingRunAgainstItsOwnResult(): void
    {
        $migrations = $this->loader()->load();

        foreach ($migrations as $migration) {
            foreach ($migration->statements as $statement) {
                $this->pdo->exec($statement);
                // Twice. Anything that is not idempotent throws here.
                $this->pdo->exec($statement);
            }
        }

        self::assertTrue($this->tableExists('mailadmin_setting'));
    }

    public function testTheRecordedTimestampIsUtc(): void
    {
        $this->migrator()->migrate();

        /** @var mixed $appliedAt */
        $appliedAt = $this->connection->selectValue(
            'SELECT applied_at FROM mailadmin_schema_version WHERE version = 1',
        );

        self::assertIsString($appliedAt);

        $recorded = new \DateTimeImmutable($appliedAt, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertLessThan(
            120,
            abs($now->getTimestamp() - $recorded->getTimestamp()),
            'The recorded time is more than two minutes from now, so it is not UTC.',
        );
    }

    /**
     * Two runs at the same time must not both migrate. The second one waits for
     * `GET_LOCK` and then gives up without touching anything.
     */
    public function testASecondRunCannotStartWhileTheFirstHoldsTheLock(): void
    {
        $other = $this->secondConnection();

        $lockName = MigrationLock::forDatabase(
            $this->connection,
            $this->configuration()->databaseName(),
        )->name();

        // The other connection takes the lock and keeps it.
        self::assertSame(
            1,
            (int) $other->selectValue(
                'SELECT GET_LOCK(:name, 5)',
                ['name' => $lockName],
            ),
        );

        try {
            $migrator = new Migrator(
                $this->connection,
                $this->loader(),
                new SchemaVersionTable($this->connection),
                MigrationLock::forDatabase(
                    $this->connection,
                    $this->configuration()->databaseName(),
                    timeoutSeconds: 1,
                ),
            );

            try {
                $migrator->migrate();
                self::fail('Expected the second run to be refused.');
            } catch (MigrationError $error) {
                self::assertSame(ErrorCode::MIGRATION_LOCKED, $error->errorCode());
            }

            // Nothing happened: the schema is still unmigrated.
            self::assertFalse($this->tableExists('mailadmin_schema_version'));
        } finally {
            $other->selectValue('SELECT RELEASE_LOCK(:name)', ['name' => $lockName]);
        }
    }

    public function testTheLockIsScopedToTheDatabase(): void
    {
        $name = MigrationLock::forDatabase($this->connection, 'mail')->name();

        self::assertSame(MigrationLock::PREFIX . ':mail', $name);
        self::assertNotSame(
            $name,
            MigrationLock::forDatabase($this->connection, 'other')->name(),
        );
    }

    public function testTheGuardRefusesBeforeAndPassesAfter(): void
    {
        $guard = $this->guard();

        try {
            $guard->verify();
            self::fail('Expected the guard to refuse an unmigrated database.');
        } catch (SchemaOutdated $error) {
            self::assertSame(ErrorCode::SCHEMA_OUTDATED, $error->errorCode());
        }

        $this->migrator()->migrate();

        self::assertTrue($this->guard()->isSatisfied());
    }

    /**
     * `MAILADMIN_AUTO_MIGRATE` on: the guard migrates instead of refusing, under
     * the same lock the command line uses (F3 open point 6).
     */
    public function testAutoMigrateBringsTheSchemaUpUnderTheLock(): void
    {
        $guard = $this->guard(autoMigrate: true);

        $guard->verify();

        self::assertTrue($this->tableExists('mailadmin_setting'));
        self::assertTrue($guard->isSatisfied());
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $fingerprint
     */
    private static function columnTypeIn(array $fingerprint, string $table, string $column): string
    {
        /** @var mixed $columns */
        $columns = $fingerprint[$table]['columns'] ?? [];

        if (!is_array($columns)) {
            return '';
        }

        /** @var mixed $definition */
        foreach ($columns as $definition) {
            if (is_array($definition) && ($definition['column_name'] ?? null) === $column) {
                return is_scalar($definition['column_type'] ?? null)
                    ? (string) $definition['column_type']
                    : '';
            }
        }

        return '';
    }

    /**
     * The fingerprint with the two widened `column_type` values blanked out, so
     * that everything else about those columns is still compared.
     *
     * @param array<string, mixed> $fingerprint
     *
     * @return array<string, mixed>
     */
    private static function withoutPasswordColumnType(array $fingerprint): array
    {
        foreach (['accountuser', 'adminuser'] as $table) {
            /** @var mixed $columns */
            $columns = $fingerprint[$table]['columns'] ?? null;

            if (!is_array($columns)) {
                continue;
            }

            /** @var mixed $definition */
            foreach ($columns as $index => $definition) {
                if (is_array($definition) && ($definition['column_name'] ?? null) === 'password') {
                    $fingerprint[$table]['columns'][$index]['column_type'] = '<width ignored>';
                }
            }
        }

        return $fingerprint;
    }

    /**
     * Engine, collation, every column definition and the row count of every
     * legacy table — enough that any change a migration made to one of them
     * shows up as a diff.
     *
     * @return array<string, mixed>
     */
    private function legacyFingerprint(): array
    {
        $fingerprint = [];

        foreach (['domain', 'accountuser', 'virtual', 'adminuser', 'domainadmin', 'settings'] as $table) {
            /** @var array<string, mixed>|null $row */
            $row = $this->connection->selectOne(
                'SELECT engine, table_collation FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :name',
                ['name' => $table],
            );

            $columns = $this->connection->select(
                'SELECT column_name, column_type, is_nullable, column_default '
                . 'FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = :name ORDER BY ordinal_position',
                ['name' => $table],
            );

            $fingerprint[$table] = [
                'table' => $row,
                'columns' => $columns,
                'rows' => (int) $this->connection->selectValue(
                    sprintf('SELECT COUNT(*) FROM `%s`', $table),
                ),
            ];
        }

        return $fingerprint;
    }

    private function configuration(): DatabaseConfiguration
    {
        return DatabaseConfiguration::create(
            dsn: (string) self::env(self::DSN),
            user: self::env(self::USER) ?? 'root',
            password: self::env(self::PASSWORD) ?? '',
            timeoutSeconds: 3,
        );
    }

    private function secondConnection(): Connection
    {
        return new Connection(new ConnectionFactory($this->configuration()));
    }

    private function loader(): MigrationLoader
    {
        return new MigrationLoader(dirname(__DIR__, 3) . '/migrations');
    }

    private function migrator(): Migrator
    {
        return new Migrator(
            $this->connection,
            $this->loader(),
            new SchemaVersionTable($this->connection),
            MigrationLock::forDatabase($this->connection, $this->configuration()->databaseName()),
        );
    }

    private function guard(bool $autoMigrate = false): SchemaGuard
    {
        return new SchemaGuard(
            versions: new SchemaVersionTable($this->connection),
            loader: $this->loader(),
            migrator: $this->migrator(),
            autoMigrate: $autoMigrate,
        );
    }
}

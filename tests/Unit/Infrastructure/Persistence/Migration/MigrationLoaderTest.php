<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Migration;

use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\Migration\Migration;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationError;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationLoader::class)]
#[CoversClass(Migration::class)]
final class MigrationLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/mailadmin-migrations-' . bin2hex(random_bytes(6));
        mkdir($directory);

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testLoadsInNumericOrderRegardlessOfWhenAFileArrived(): void
    {
        $this->write('0020_audit.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_audit (id INT);');
        $this->write('0001_schema_version.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_v (id INT);');
        $this->write('0010_token.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_token (id INT);');

        $numbers = array_map(
            static fn (Migration $migration): int => $migration->number,
            (new MigrationLoader($this->directory))->load(),
        );

        self::assertSame([1, 10, 20], $numbers);
    }

    /**
     * The reserved ranges in U-6 leave gaps on purpose. A gap is not a missing
     * migration.
     */
    public function testGapsInTheNumberingAreNotAProblem(): void
    {
        $this->write('0002_setting.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_setting (id INT);');
        $this->write('0050_admin_setting.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_a (id INT);');

        self::assertCount(2, (new MigrationLoader($this->directory))->load());
    }

    public function testTwoFilesWithTheSameNumberAreAnError(): void
    {
        $this->write('0010_first.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_a (id INT);');
        $this->write('0010_second.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_b (id INT);');

        try {
            (new MigrationLoader($this->directory))->load();
            self::fail('Expected a MigrationError.');
        } catch (MigrationError $error) {
            self::assertSame(ErrorCode::MIGRATION_INVALID, $error->errorCode());
            self::assertStringContainsString('share the number 0010', $error->getMessage());
        }
    }

    public function testAMisnamedFileIsAnError(): void
    {
        $this->write('create-audit.sql', 'SELECT 1;');

        $this->expectExceptionMessageMatches('/NNNN_description\.sql/');

        (new MigrationLoader($this->directory))->load();
    }

    public function testAnEmptyMigrationIsAnError(): void
    {
        $this->write('0001_nothing.sql', "-- nothing here yet\n");

        $this->expectExceptionMessageMatches('/contains no statement/');

        (new MigrationLoader($this->directory))->load();
    }

    public function testAMissingDirectoryIsAnError(): void
    {
        $this->expectException(MigrationError::class);

        (new MigrationLoader($this->directory . '/nope'))->load();
    }

    public function testTheNumbersAreReadableWithoutOpeningAFile(): void
    {
        $this->write('0001_a.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_a (id INT);');
        $this->write('0030_b.sql', 'CREATE TABLE IF NOT EXISTS mailadmin_b (id INT);');

        self::assertSame([1, 30], (new MigrationLoader($this->directory))->numbers());
    }

    public function testTheChecksumIsOverTheFileTheOperatorCanSee(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS mailadmin_a (id INT);';
        $migration = Migration::fromFile('0001_a.sql', $sql);

        self::assertSame(hash('sha256', $sql), $migration->checksum);
        self::assertSame('0001', $migration->formattedNumber());
        self::assertSame('0001 a', $migration->label());
        self::assertSame('a', $migration->name);
    }

    /**
     * U-6 gives every package a reserved range so that two of them can add a
     * migration on the same afternoon without colliding. The table is only worth
     * anything if something checks it.
     *
     * @return array<string, array{int, int, string}>
     */
    public static function reservedRanges(): array
    {
        return [
            'F3 — schema version, settings' => [1, 9, 'F3'],
            'S2 — tokens and sign-in attempts' => [10, 19, 'S2'],
            'S4 — audit log' => [20, 29, 'S4'],
            'A1 — domain settings' => [30, 39, 'A1'],
            'A6 — repair queue' => [40, 49, 'A6'],
            'A4 — per-admin settings' => [50, 59, 'A4'],
            'A2 — account status' => [60, 69, 'A2'],
            'unassigned' => [70, 99, 'nobody yet'],
        ];
    }

    public function testEveryShippedMigrationSitsInARangeSomePackageOwns(): void
    {
        $migrations = (new MigrationLoader(dirname(__DIR__, 5) . '/migrations'))->load();

        self::assertNotSame([], $migrations, 'The repository ships no migrations at all.');

        foreach ($migrations as $migration) {
            $owner = null;

            foreach (self::reservedRanges() as [$from, $to, $package]) {
                if ($migration->number >= $from && $migration->number <= $to) {
                    $owner = $package;

                    break;
                }
            }

            self::assertNotNull(
                $owner,
                $migration->fileName . ' has a number no package reserves in U-6.',
            );
            self::assertNotSame(
                'nobody yet',
                $owner,
                $migration->fileName . ' claims a number U-6 keeps free. Reserve the range first.',
            );
        }
    }

    /**
     * The full list, so that adding a migration is a deliberate act rather than
     * something that happens while nobody is looking.
     */
    public function testTheShippedMigrationsAreTheOnesThisRepositoryKnowsAbout(): void
    {
        $migrations = (new MigrationLoader(dirname(__DIR__, 5) . '/migrations'))->load();

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
            array_map(static fn (Migration $m): string => $m->label(), $migrations),
        );
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->directory . '/' . $name, $contents);
    }
}

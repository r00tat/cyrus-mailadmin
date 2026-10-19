<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Migration;

use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\Migration\AdditiveOnlyRule;
use Mailadmin\Infrastructure\Persistence\Migration\Migration;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationError;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule that keeps `CLAUDE.md` rule 1 from depending on anyone's memory.
 *
 * Every statement in the "refused" set would still be valid SQL and would still
 * run against the production database, where Postfix and `pam_mysql` are
 * reading. The point of the test is that the migrator never gets that far.
 */
#[CoversClass(AdditiveOnlyRule::class)]
final class AdditiveOnlyRuleTest extends TestCase
{
    #[DataProvider('refusedStatements')]
    public function testARefusedStatementNeverRuns(string $sql, string $expectedMessage): void
    {
        $migration = Migration::fromFile('0099_attempt.sql', $sql);

        $violations = AdditiveOnlyRule::check($migration);

        self::assertNotEmpty($violations, 'Expected the rule to refuse: ' . $sql);
        self::assertStringContainsString($expectedMessage, implode("\n", $violations));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedStatements(): iterable
    {
        yield 'drop a legacy table' => [
            'DROP TABLE accountuser;',
            'DROP TABLE is never allowed',
        ];

        yield 'drop one of our own tables' => [
            'DROP TABLE mailadmin_setting;',
            'DROP TABLE is never allowed',
        ];

        yield 'drop if exists' => [
            'DROP TABLE IF EXISTS domain;',
            'DROP TABLE is never allowed',
        ];

        yield 'drop a column from a legacy table' => [
            'ALTER TABLE accountuser DROP COLUMN sieve;',
            'DROP COLUMN is never allowed',
        ];

        yield 'drop a column without the COLUMN keyword' => [
            'ALTER TABLE `virtual` DROP `status`;',
            'DROP COLUMN is never allowed',
        ];

        yield 'rename a table' => [
            'RENAME TABLE domain TO domains;',
            'RENAME TABLE is never allowed',
        ];

        yield 'rename through ALTER' => [
            'ALTER TABLE domain RENAME TO domains;',
            'RENAME is never allowed',
        ];

        yield 'rename a column' => [
            'ALTER TABLE accountuser RENAME COLUMN prefix TO domain_prefix;',
            'RENAME is never allowed',
        ];

        yield 'narrow a legacy column' => [
            'ALTER TABLE accountuser MODIFY password VARCHAR(30) NOT NULL;',
            'MODIFY, CHANGE, CONVERT TO and ENGINE',
        ];

        yield 'change a legacy column' => [
            'ALTER TABLE domain CHANGE quota quota INT NOT NULL;',
            'MODIFY, CHANGE, CONVERT TO and ENGINE',
        ];

        yield 'convert a legacy table charset' => [
            'ALTER TABLE domain CONVERT TO CHARACTER SET utf8mb4;',
            'MODIFY, CHANGE, CONVERT TO and ENGINE',
        ];

        yield 'truncate' => [
            'TRUNCATE TABLE virtual;',
            'TRUNCATE is never allowed',
        ];

        yield 'replace a view Salt owns' => [
            'CREATE OR REPLACE VIEW allusers AS SELECT 1;',
            'unsupported statement',
        ];

        yield 'drop a view' => [
            'DROP VIEW validsenders;',
            'DROP is never allowed',
        ];

        yield 'a stored routine' => [
            'CREATE TRIGGER t BEFORE INSERT ON domain FOR EACH ROW SET @x = 1;',
            'unsupported statement',
        ];

        yield 'grant' => [
            "GRANT ALL ON mail.* TO 'someone';",
            'unsupported statement',
        ];

        yield 'a server-wide setting' => [
            "SET GLOBAL sql_mode = '';",
            'SET GLOBAL changes the server',
        ];

        yield 'a new table without our prefix' => [
            'CREATE TABLE IF NOT EXISTS token (id INT);',
            'new tables carry the prefix',
        ];

        yield 'a new table that is not repeatable' => [
            'CREATE TABLE mailadmin_thing (id INT);',
            'must say IF NOT EXISTS',
        ];

        yield 'an index that is not repeatable' => [
            'CREATE INDEX idx ON mailadmin_thing (id);',
            'must say IF NOT EXISTS',
        ];

        yield 'an index on a legacy table' => [
            'CREATE INDEX IF NOT EXISTS idx ON virtual (alias);',
            'only tables with the prefix',
        ];

        yield 'a column on a legacy table without a default' => [
            'ALTER TABLE accountuser ADD COLUMN IF NOT EXISTS note VARCHAR(50) NOT NULL;',
            'needs a DEFAULT or must be nullable',
        ];

        yield 'a unique key on a legacy table' => [
            'ALTER TABLE domain ADD UNIQUE KEY IF NOT EXISTS uq_name (domain_name);',
            'not additive in effect',
        ];

        yield 'a column that is not repeatable' => [
            "ALTER TABLE mailadmin_setting ADD COLUMN note VARCHAR(50) NOT NULL DEFAULT '';",
            'must say IF NOT EXISTS',
        ];

        yield 'seeding a legacy table' => [
            "INSERT IGNORE INTO domain (domain_name) VALUES ('example.test');",
            'a migration seeds its own tables',
        ];

        yield 'an insert that fails on the second run' => [
            "INSERT INTO mailadmin_setting (name, value, updated_at) VALUES ('a', 'b', '2026-01-01 00:00:00');",
            'must be INSERT IGNORE',
        ];

        yield 'updating a legacy table' => [
            "UPDATE accountuser SET password = '';",
            'may only touch data in tables with the prefix',
        ];

        yield 'deleting from a legacy table' => [
            'DELETE FROM virtual WHERE status = 0;',
            'may only touch data in tables with the prefix',
        ];

        yield 'a comment does not disarm the rule' => [
            '/* just tidying up */ DROP TABLE domain;',
            'DROP TABLE is never allowed',
        ];

        yield 'a second statement is checked too' => [
            "CREATE TABLE IF NOT EXISTS mailadmin_ok (id INT);\nDROP TABLE domain;",
            'DROP TABLE is never allowed',
        ];
    }

    #[DataProvider('acceptedStatements')]
    public function testAnAdditiveStatementIsAccepted(string $sql): void
    {
        $migration = Migration::fromFile('0099_attempt.sql', $sql);

        self::assertSame([], AdditiveOnlyRule::check($migration));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedStatements(): iterable
    {
        yield 'a new table of ours' => [
            'CREATE TABLE IF NOT EXISTS `mailadmin_thing` (`id` INT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (`id`)) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;',
        ];

        yield 'an index on one of ours' => [
            'CREATE INDEX IF NOT EXISTS idx_thing ON mailadmin_thing (id);',
        ];

        yield 'a column on one of ours' => [
            "ALTER TABLE mailadmin_setting ADD COLUMN IF NOT EXISTS note VARCHAR(50) NOT NULL DEFAULT '';",
        ];

        yield 'a nullable column on a legacy table' => [
            'ALTER TABLE accountuser ADD COLUMN IF NOT EXISTS mailadmin_note VARCHAR(50) NULL;',
        ];

        yield 'a defaulted column on a legacy table' => [
            'ALTER TABLE domain ADD COLUMN IF NOT EXISTS mailadmin_flag TINYINT NOT NULL DEFAULT 0;',
        ];

        yield 'seeding one of our tables repeatably' => [
            'INSERT IGNORE INTO mailadmin_setting (name, value, updated_at) '
            . "VALUES ('accounts.default_quota', '20000', '2026-01-01 00:00:00');",
        ];

        yield 'an upsert' => [
            'INSERT INTO mailadmin_setting (name, value, updated_at) '
            . "VALUES ('a', 'b', '2026-01-01 00:00:00') ON DUPLICATE KEY UPDATE value = VALUES(value);",
        ];

        yield 'a session setting' => [
            "SET SESSION time_zone = '+00:00';",
        ];

        yield 'reading' => [
            'SELECT COUNT(*) FROM accountuser;',
        ];
    }

    public function testEnforceThrowsWithTheRejectedCode(): void
    {
        $migration = Migration::fromFile('0099_attempt.sql', 'DROP TABLE domain;');

        try {
            AdditiveOnlyRule::enforce($migration);
            self::fail('Expected the migration to be rejected.');
        } catch (MigrationError $error) {
            self::assertSame(ErrorCode::MIGRATION_REJECTED, $error->errorCode());
            self::assertStringContainsString('0099_attempt.sql', $error->getMessage());
        }
    }

    public function testTheViolationNamesTheStatementNumber(): void
    {
        $migration = Migration::fromFile(
            '0099_attempt.sql',
            "SELECT 1;\nSELECT 2;\nDROP TABLE domain;",
        );

        self::assertStringStartsWith('statement 3:', AdditiveOnlyRule::check($migration)[0]);
    }

    /**
     * The migrations this repository actually ships have to pass their own rule.
     */
    public function testEveryShippedMigrationSatisfiesTheRule(): void
    {
        $loader = new MigrationLoader(dirname(__DIR__, 5) . '/migrations');

        $migrations = $loader->load();

        self::assertNotEmpty($migrations);

        foreach ($migrations as $migration) {
            self::assertSame(
                [],
                AdditiveOnlyRule::check($migration),
                $migration->fileName . ' breaks the additive-only rule',
            );
        }
    }
}

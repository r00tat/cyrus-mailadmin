<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Infrastructure\Persistence\AccountRepository;
use Mailadmin\Infrastructure\Persistence\AccountStatusEnforcement;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * What `bin/db-check.php` says about the deployed `allusers` (U-127, U-129),
 * against views that filter both, one or none of the two `UNION` branches.
 *
 * A view that filters only one branch lets a disabled account log in under
 * the other spelling; neither check may call that "honoured".
 *
 * Skips without a database. It never passes without one.
 */
#[CoversClass(AccountStatusEnforcement::class)]
final class AccountStatusEnforcementIntegrationTest extends DatabaseTestCase
{
    private const string FILTER = ' LEFT JOIN mailadmin_account_status '
        . 'ON mailadmin_account_status.username = accountuser.username '
        . 'WHERE mailadmin_account_status.username IS NULL OR mailadmin_account_status.disabled = 0';

    private const string ADDRESS_BRANCH = "SELECT CONCAT(REPLACE(accountuser.username, CONCAT('.', accountuser.domain_name), ''), "
        . "'@', accountuser.domain_name) AS username, accountuser.password AS password FROM accountuser";

    private const string STORED_BRANCH = 'SELECT accountuser.username AS username, accountuser.password AS password FROM accountuser';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->exec('DROP VIEW IF EXISTS `allusers`');
        $this->createLegacyBaseline();
        $this->dropIfPresent('mailadmin_account_status');
        $this->willCreate('mailadmin_account_status');

        foreach ((new MigrationLoader(dirname(__DIR__, 3) . '/migrations'))->load() as $migration) {
            if ($migration->number !== 60) {
                continue;
            }

            foreach ($migration->statements as $statement) {
                $this->pdo->exec($statement);
            }
        }

        $this->pdo->exec(
            'INSERT INTO mailadmin_account_status (username, disabled, disabled_at, disabled_by, updated_at, updated_by) '
            . 'VALUES ("info.example.test", 1, UTC_TIMESTAMP(), "anna", UTC_TIMESTAMP(), "anna")',
        );
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP VIEW IF EXISTS `allusers`');

        parent::tearDown();
    }

    public function testAViewFilteringBothBranchesIsHonoured(): void
    {
        $this->createView(self::ADDRESS_BRANCH . self::FILTER, self::STORED_BRANCH . self::FILTER);

        self::assertTrue(AccountStatusEnforcement::definitionHonoursStatus($this->definition()));
        self::assertSame(0, $this->enforcement()->leakingAccounts());
    }

    public function testAViewFilteringOnlyTheStoredNameLeaksTheAddressForm(): void
    {
        $this->createView(self::ADDRESS_BRANCH, self::STORED_BRANCH . self::FILTER);

        self::assertSame(['branches' => 2, 'honouring' => 1], AccountStatusEnforcement::branches($this->definition()));
        self::assertFalse(AccountStatusEnforcement::definitionHonoursStatus($this->definition()));
        // `info@example.test` still authenticates.
        self::assertSame(1, $this->enforcement()->leakingAccounts());
    }

    public function testAViewFilteringOnlyTheAddressFormLeaksTheStoredName(): void
    {
        $this->createView(self::ADDRESS_BRANCH . self::FILTER, self::STORED_BRANCH);

        self::assertFalse(AccountStatusEnforcement::definitionHonoursStatus($this->definition()));
        self::assertSame(1, $this->enforcement()->leakingAccounts());
    }

    public function testTheOldViewIsNotHonoured(): void
    {
        $this->createView(self::ADDRESS_BRANCH, self::STORED_BRANCH);

        self::assertSame(['branches' => 2, 'honouring' => 0], AccountStatusEnforcement::branches($this->definition()));
        self::assertFalse(AccountStatusEnforcement::definitionHonoursStatus($this->definition()));
        self::assertSame(1, $this->enforcement()->leakingAccounts());
    }

    public function testALegacyColumnInAnotherLatin1CollationStillJoinsByBytes(): void
    {
        // The baseline's `latin1_bin` is inferred, not measured. With the
        // MariaDB default instead, the _ci/_bin mix resolves to latin1_bin:
        // no "illegal mix of collations", and the join still compares bytes.
        $this->pdo->exec(
            "ALTER TABLE accountuser MODIFY username VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT ''",
        );
        // A status row under another spelling of the same name.
        $this->pdo->exec('UPDATE mailadmin_account_status SET username = "Info.example.test"');
        $this->createView(self::ADDRESS_BRANCH . self::FILTER, self::STORED_BRANCH . self::FILTER);

        self::assertContains(
            'info.example.test',
            array_column($this->connection->select('SELECT username FROM allusers'), 'username'),
        );
        self::assertTrue($this->enabled('info.example.test'));

        $this->pdo->exec('UPDATE mailadmin_account_status SET username = "info.example.test"');

        self::assertSame(0, $this->enforcement()->leakingAccounts());
        self::assertFalse($this->enabled('info.example.test'));
    }

    private function enabled(string $storedUsername): bool
    {
        $record = (new AccountRepository($this->connection))->find($storedUsername);
        self::assertNotNull($record);

        return $record->status->enabled;
    }

    private function createView(string $first, string $second): void
    {
        try {
            $this->pdo->exec('CREATE OR REPLACE VIEW `allusers` AS ' . $first . ' UNION ' . $second);
        } catch (PDOException $error) {
            // An environment set up before the grant existed.
            if (str_contains($error->getMessage(), '1142')) {
                self::markTestSkipped('No CREATE VIEW on the disposable database; run `bin/test-env reset`.');
            }

            throw $error;
        }
    }

    /**
     * As `bin/db-check.php` reads it — MariaDB's normalised text, not ours.
     */
    private function definition(): string
    {
        $definition = $this->connection->selectValue(
            'SELECT view_definition FROM information_schema.views WHERE table_schema = DATABASE() AND table_name = :v',
            ['v' => 'allusers'],
        );
        self::assertIsString($definition);

        return $definition;
    }

    private function enforcement(): AccountStatusEnforcement
    {
        return new AccountStatusEnforcement($this->connection);
    }
}

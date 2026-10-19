<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Infrastructure\Persistence\AdminRepository;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * {@see AdminRepository} against a real MariaDB with the legacy schema.
 *
 * The use-case tests run against the in-memory double and cover the rules. What
 * only a database can answer is here: that the wildcard row is written the way
 * webcyradm writes it, that `latin1_bin` really does treat `Admin` and `admin`
 * as two accounts, that the `LEFT JOIN` finds an orphaned assignment, and that
 * deleting an administrator takes the rows in three other tables with it.
 *
 * Skips without a database, and never passes without one (see
 * {@see DatabaseTestCase}).
 */
#[CoversClass(AdminRepository::class)]
final class AdminRepositoryIntegrationTest extends DatabaseTestCase
{
    /** Not a real hash and not a real password — 60 characters shaped like bcrypt. */
    private const string HASH = '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU';

    private AdminRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyBaseline();
        $this->widenAdminPasswordColumn();
        $this->createAdminSettingTable();

        $this->repository = new AdminRepository($this->connection);
    }

    public function testASuperuserIsWrittenWithTheWildcardRow(): void
    {
        $this->repository->create('root', self::HASH, AdminRole::Superuser, []);

        $rows = $this->connection->select(
            'SELECT `domain_name` FROM `domainadmin` WHERE `adminuser` = :u',
            ['u' => 'root'],
        );

        self::assertSame([['domain_name' => AdminRole::ALL_DOMAINS]], $rows);

        $created = $this->repository->find('root');

        self::assertNotNull($created);
        self::assertTrue($created->allDomains);
        self::assertSame([], $created->domains);
        self::assertSame(PasswordFormat::Bcrypt, $created->passwordFormat);
    }

    public function testADomainMasterGetsOneRowPerDomain(): void
    {
        $this->repository->create('master', self::HASH, AdminRole::DomainMaster, ['example.test']);

        $found = $this->repository->find('master');

        self::assertNotNull($found);
        self::assertFalse($found->allDomains);
        self::assertSame(['example.test'], $found->domains);
        self::assertSame([], $found->orphanedDomains);
    }

    /**
     * U-22: the assignment stays visible and is marked, rather than being
     * dropped by the join.
     */
    public function testAnAssignmentToAMissingDomainIsReportedAsOrphaned(): void
    {
        $this->repository->create('master', self::HASH, AdminRole::DomainMaster, ['example.test']);
        $this->connection->execute(
            "INSERT INTO `domainadmin` (`domain_name`, `adminuser`) VALUES ('gone.test', 'master')",
        );

        $found = $this->repository->find('master');

        self::assertNotNull($found);
        self::assertSame(['example.test', 'gone.test'], $found->domains);
        self::assertSame(['gone.test'], $found->orphanedDomains);
    }

    /**
     * `adminuser.username` is declared `BINARY` / `latin1_bin`. Two accounts, and
     * this repository must not fold them into one.
     */
    public function testTheNameComparisonIsByteWise(): void
    {
        $this->repository->create('root', self::HASH, AdminRole::Superuser, []);
        $this->repository->create('Root', self::HASH, AdminRole::DomainMaster, ['example.test']);

        self::assertTrue($this->repository->exists('root'));
        self::assertTrue($this->repository->exists('Root'));
        self::assertSame(AdminRole::Superuser, $this->repository->find('root')?->role);
        self::assertSame(AdminRole::DomainMaster, $this->repository->find('Root')?->role);
    }

    public function testTheListingPagesSortsAndFiltersThroughTheAllowlist(): void
    {
        $this->repository->create('carla', self::HASH, AdminRole::Superuser, []);
        $this->repository->create('anna', self::HASH, AdminRole::DomainMaster, ['example.test']);
        $this->repository->create('bruno', self::HASH, AdminRole::DomainMaster, ['example.test']);

        $page = $this->repository->list(new ListCriteria(page: 1, perPage: 2));

        self::assertSame(3, $page->total);
        self::assertSame(['anna', 'bruno'], array_column(
            array_map(static fn (object $a): array => ['u' => $a->username], $page->items),
            'u',
        ));

        $descending = $this->repository->list(new ListCriteria(sort: 'username', order: SortDirection::Descending));

        self::assertSame('carla', $descending->items[0]->username);

        $masters = $this->repository->list(
            new ListCriteria(filters: ['role' => AdminRole::DomainMaster->toLegacyType()]),
        );

        self::assertSame(2, $masters->total);

        $matching = $this->repository->list(new ListCriteria(filters: ['username' => 'ann']));

        self::assertSame(1, $matching->total);
        self::assertSame('anna', $matching->items[0]->username);
    }

    /**
     * The one thing the allowlist exists for (`CLAUDE.md`, rule 2): a field the
     * request invents is refused, not interpolated and not ignored.
     */
    public function testAnUndeclaredSortFieldIsRefused(): void
    {
        $this->repository->create('root', self::HASH, AdminRole::Superuser, []);

        $this->expectException(CriteriaError::class);

        $this->repository->list(new ListCriteria(sort: 'password'));
    }

    public function testSuperusersAreCounted(): void
    {
        $this->repository->create('root', self::HASH, AdminRole::Superuser, []);
        $this->repository->create('second', self::HASH, AdminRole::Superuser, []);
        $this->repository->create('master', self::HASH, AdminRole::DomainMaster, ['example.test']);

        self::assertSame(2, $this->repository->countSuperusers());
    }

    public function testOnlyDomainsWithARowAreReportedAsExisting(): void
    {
        self::assertSame(
            ['example.test'],
            $this->repository->existingDomains(['example.test', 'gone.test']),
        );
        self::assertSame([], $this->repository->existingDomains([]));
    }

    public function testReplacingTheAssignmentRewritesBothTheRoleAndTheRows(): void
    {
        $this->repository->create('master', self::HASH, AdminRole::DomainMaster, ['example.test']);

        $this->repository->replaceAssignment('master', AdminRole::Superuser, []);

        $promoted = $this->repository->find('master');

        self::assertNotNull($promoted);
        self::assertSame(AdminRole::Superuser, $promoted->role);
        self::assertTrue($promoted->allDomains);
        self::assertSame([], $promoted->domains);

        $this->repository->replaceAssignment('master', AdminRole::DomainMaster, ['example.test']);

        $demoted = $this->repository->find('master');

        self::assertNotNull($demoted);
        self::assertFalse($demoted->allDomains);
        self::assertSame(['example.test'], $demoted->domains);
    }

    public function testTheHashIsReadBackAndReplaced(): void
    {
        $this->repository->create('root', self::HASH, AdminRole::Superuser, []);

        self::assertSame(self::HASH, $this->repository->findPasswordHash('root'));

        // A 97-character Argon2id-shaped value: the reason migration 0003 widens
        // the column at all. On an unwidened `VARCHAR(50)` this either fails or
        // truncates to a hash that can never verify again.
        $argon = '$argon2id$v=19$m=65536,t=4,p=1$' . str_repeat('a', 22) . '$' . str_repeat('b', 43);

        $this->repository->replacePasswordHash('root', $argon);

        self::assertSame($argon, $this->repository->findPasswordHash('root'));
        self::assertNull($this->repository->findPasswordHash('nobody'));
    }

    /**
     * A4's definition of done: deleting an administrator removes the
     * `domainadmin` and `settings` rows too — webcyradm did, and leaving them
     * would hand the preferences of a departed administrator to the next person
     * given the same name.
     */
    public function testDeletingAnAdministratorRemovesEveryRowKeyedByTheirName(): void
    {
        $this->repository->create('master', self::HASH, AdminRole::DomainMaster, ['example.test']);
        $this->repository->create('other', self::HASH, AdminRole::DomainMaster, ['example.test']);

        $this->connection->execute(
            'INSERT INTO `settings` (`username`, `style`, `maxdisplay`, `warnlevel`) '
            . "VALUES ('master', 'default', 15, 90), ('other', 'default', 25, 80)",
        );
        $this->connection->execute(
            'INSERT INTO `mailadmin_admin_setting` (`username`, `name`, `value`, `updated_at`) '
            . "VALUES ('master', 'ui.locale', 'de', '2026-01-01 00:00:00'), "
            . "('other', 'ui.locale', 'de', '2026-01-01 00:00:00')",
        );

        $this->repository->delete('master');

        self::assertNull($this->repository->find('master'));
        self::assertSame(0, $this->countWhere('domainadmin', 'adminuser', 'master'));
        self::assertSame(0, $this->countWhere('settings', 'username', 'master'));
        self::assertSame(0, $this->countWhere('mailadmin_admin_setting', 'username', 'master'));

        // And nobody else's rows went with them.
        self::assertNotNull($this->repository->find('other'));
        self::assertSame(1, $this->countWhere('domainadmin', 'adminuser', 'other'));
        self::assertSame(1, $this->countWhere('settings', 'username', 'other'));
        self::assertSame(1, $this->countWhere('mailadmin_admin_setting', 'username', 'other'));
    }

    private function countWhere(string $table, string $column, string $value): int
    {
        return (int) $this->connection->selectValue(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = :v', $table, $column),
            ['v' => $value],
        );
    }

    /**
     * Migration 0003, applied by hand for the one column this suite writes. The
     * legacy `varchar(50)` cannot hold a bcrypt hash, let alone Argon2id; the
     * repository only ever runs against a migrated schema.
     */
    private function widenAdminPasswordColumn(): void
    {
        $this->pdo->exec(
            'ALTER TABLE `adminuser` MODIFY `password` VARCHAR(255) '
            . "CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT ''",
        );
    }

    /**
     * Migration 0050, applied by hand. The baseline this suite builds is the
     * legacy schema; the `mailadmin_` tables come from the migrator, and this
     * test only needs the one it deletes from.
     */
    private function createAdminSettingTable(): void
    {
        $this->dropIfPresent('mailadmin_admin_setting');
        $this->willCreate('mailadmin_admin_setting');

        $this->pdo->exec(
            'CREATE TABLE `mailadmin_admin_setting` (
                `username`   VARCHAR(190) NOT NULL,
                `name`       VARCHAR(190) NOT NULL,
                `value`      TEXT         NULL,
                `updated_at` DATETIME     NOT NULL,
                PRIMARY KEY (`username`, `name`),
                KEY `mailadmin_admin_setting_name` (`name`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci',
        );
    }
}

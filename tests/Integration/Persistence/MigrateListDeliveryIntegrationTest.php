<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Application\MailingList\ListDeliveryMigration;
use Mailadmin\Application\MailingList\MigrateListDelivery;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\DomainRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\SqlMailingListRepository;
use Mailadmin\Infrastructure\Persistence\VirtualRepository;
use Mailadmin\Tests\Support\FrozenClock;
use Mailadmin\Tests\Support\ListDeliveryFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * The list delivery changeover (spec 6, step 4) against MariaDB with the
 * production definitions of `lists` and `virtual` — MyISAM, latin1, the
 * `id` columns the production tables carry.
 *
 * What only a real database shows: the single `UPDATE` matches the row by
 * its four fields and touches no other, and the rows Postfix reads afterwards
 * are exactly the transport form — and, after `--revert`, exactly the legacy
 * form again.
 *
 * Skips without a database. It never passes without one.
 */
#[CoversClass(MigrateListDelivery::class)]
#[CoversClass(VirtualRepository::class)]
final class MigrateListDeliveryIntegrationTest extends DatabaseTestCase
{
    private InMemoryAuditTrail $trail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyBaseline();
        $this->connection->execute('DELETE FROM virtual');

        // The production definitions (plan, "Fakten aus Produktion").
        $this->pdo->exec('DROP TABLE `virtual`');
        $this->pdo->exec(
            "CREATE TABLE `virtual` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `alias` varchar(255) NOT NULL DEFAULT '',
                `dest` longtext DEFAULT NULL,
                `username` varchar(50) NOT NULL DEFAULT '',
                `status` int(11) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                KEY `alias` (`alias`)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci",
        );

        $this->dropIfPresent('lists');
        $this->willCreate('lists');
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

        $this->connection->execute(
            'INSERT INTO lists (name, tag, address, members) VALUES '
            . '("Team", "team", "team@example.test", "anna@example.test"), '
            . '("Crew", "crew", "crew@example.test", "anna@example.test"), '
            . '("Orphan", "orphan", "orphan@example.test", "anna@example.test"), '
            . '("Plus", "plus", "plus+x@example.test", "anna@example.test")',
        );
        $this->connection->execute(
            'INSERT INTO virtual (alias, dest, username) VALUES '
            . '("team@example.test", "team.example.test", "team.example.test"), '
            . '("crew@example.test", "crew.example.test", "crew.example.test"), '
            . '("plus+x@example.test", "plus+x.example.test", "plus+x.example.test"), '
            // An account address in the dotted scheme: the same shape as a
            // legacy list row, but there is no list for it.
            . '("anna@example.test", "anna.example.test", "anna.example.test")',
        );

        $this->trail = new InMemoryAuditTrail();
    }

    public function testForwardSecondRunAndRevert(): void
    {
        $legacy = $this->rows();

        $first = $this->changeover(false);

        self::assertSame(
            [
                'migrated' => 2, 'reverted' => 0, 'unchanged' => 0,
                'no virtual entry' => 1, 'skipped' => 1, 'failed' => 0,
            ],
            $first->totals(),
        );
        self::assertSame(
            [
                ['anna@example.test', 'anna.example.test', 'anna.example.test', '1'],
                ['crew@example.test', 'crew.example.test@maillist.invalid', '', '1'],
                ['plus+x@example.test', 'plus+x.example.test', 'plus+x.example.test', '1'],
                ['team@example.test', 'team.example.test@maillist.invalid', '', '1'],
            ],
            $this->rows(),
        );
        self::assertCount(2, $this->trail->withAction(MigrateListDelivery::MIGRATE));

        $afterFirst = $this->rows();
        $second = $this->changeover(false);

        self::assertSame($afterFirst, $this->rows());
        self::assertSame(2, $second->totals()['unchanged']);
        self::assertCount(2, $this->trail->withAction(MigrateListDelivery::MIGRATE));

        $revert = $this->changeover(true);

        self::assertSame(2, $revert->totals()['reverted']);
        self::assertSame($legacy, $this->rows());
        self::assertCount(2, $this->trail->withAction(MigrateListDelivery::REVERT));
    }

    public function testADryRunWritesNothing(): void
    {
        $before = $this->rows();

        $result = $this->changeover(false, dryRun: true);

        self::assertSame(2, $result->totals()['migrated']);
        self::assertSame($before, $this->rows());
        self::assertTrue($this->trail->isEmpty());
    }

    private function changeover(bool $revert, bool $dryRun = false): ListDeliveryMigration
    {
        $migrate = new MigrateListDelivery(
            new SqlMailingListRepository($this->connection),
            new VirtualRepository($this->connection),
            new DomainRepository($this->connection, new FrozenClock()),
            ListDeliveryFixture::delivery(),
            $this->trail,
            new NullLogger(),
        );

        return $migrate->handle(ActorContext::system('migrate-list-delivery'), $revert, $dryRun);
    }

    /**
     * @return list<list<string>>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_values($row)),
            $this->connection->select('SELECT alias, COALESCE(dest, "") AS dest, username, status FROM virtual ORDER BY alias'),
        );
    }
}

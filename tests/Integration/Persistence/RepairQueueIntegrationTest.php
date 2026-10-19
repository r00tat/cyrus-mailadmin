<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Maintenance\FindingCategory;
use Mailadmin\Application\Maintenance\Reconcile;
use Mailadmin\Application\Maintenance\RepairResolution;
use Mailadmin\Application\Maintenance\RepairStatus;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ConnectionFactory;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\DatabaseConfiguration;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\RepairQueueRepository;
use Mailadmin\Infrastructure\Persistence\SqlMaintenanceInventory;
use Mailadmin\Infrastructure\Repair\LoggingRepairQueue;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * `mailadmin_repair_queue` and the reconciliation's reads against a real
 * MariaDB (A6, U-117, U-119).
 *
 * What only a database can be wrong about: the insert and its JSON column, the
 * `DATETIME` round trip, the conditional `UPDATE` that closes an entry once,
 * the ordering with its tiebreaker — and that an entry written on the queue's
 * own connection survives the rollback of the operation that failed.
 *
 * Skips without a database. It never passes without one.
 */
#[CoversClass(RepairQueueRepository::class)]
#[CoversClass(SqlMaintenanceInventory::class)]
final class RepairQueueIntegrationTest extends DatabaseTestCase
{
    private FrozenClock $clock;
    private AuditRequestContext $context;

    /** @var list<array{string, string, array<mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock();
        $this->context = new AuditRequestContext();
        $this->context->bind('0123456789abcdef0123456789abcdef', '203.0.113.7');
        $this->logged = [];

        $this->applyOwnMigration();
    }

    public function testAnEntrySurvivesTheRoundTripThroughTheTable(): void
    {
        $repository = $this->repository();
        $repository->record(self::request('mailstore.default_folders', [
            'account' => 'anna.example.test',
            'domain' => 'example.test',
            'folders' => 'Sent,Trash',
        ]));

        $entries = $repository->list(new ListCriteria())->items;

        self::assertCount(1, $entries);
        $entry = $entries[0];

        self::assertSame('account.create', $entry->operation);
        self::assertSame('mailstore.default_folders', $entry->step);
        self::assertSame('user/anna.example.test', $entry->subject);
        self::assertSame(['account' => 'anna.example.test', 'domain' => 'example.test', 'folders' => 'Sent,Trash'], $entry->parameters);
        self::assertSame('0123456789abcdef0123456789abcdef', $entry->requestId);
        self::assertSame(RepairStatus::Open, $entry->status);
        self::assertSame('2026-01-01T12:00:00Z', $entry->toRepresentation()['recorded_at']);
        self::assertTrue($entry->toRepresentation()['retryable']);
        self::assertSame([], $this->logged, 'Nothing fell back to the log.');
    }

    public function testAnEntryWithoutParametersIsStoredAsNull(): void
    {
        $this->repository()->record(self::request('virtual.restore', []));

        self::assertNull($this->connection->selectValue('SELECT `parameters` FROM `mailadmin_repair_queue`'));
        self::assertSame([], $this->repository()->find(1)?->parameters);
    }

    public function testAttemptsAndResolutionAreRecordedAndAnEntryClosesOnce(): void
    {
        $repository = $this->repository();
        $repository->record(self::request('mailstore.delete_mailbox', ['account' => 'a.example.test', 'domain' => 'example.test']));
        $id = $repository->list(new ListCriteria())->items[0]->id;

        $this->clock->advance(60);
        $repository->recordAttempt($id, 'mailstore.unavailable', $this->clock->now());
        $repository->recordAttempt($id, 'mailstore.unavailable', $this->clock->now());

        $entry = $repository->find($id);
        self::assertNotNull($entry);
        self::assertSame(2, $entry->attempts);
        self::assertSame('mailstore.unavailable', $entry->lastError);
        self::assertSame('2026-01-01T12:01:00Z', $entry->toRepresentation()['last_attempt_at']);

        self::assertTrue($repository->resolve($id, RepairResolution::Dismissed, 'root', 'by hand', $this->clock->now()));
        self::assertFalse(
            $repository->resolve($id, RepairResolution::Retried, 'other', '', $this->clock->now()),
            'A second close changes nothing: the WHERE carries the open status.',
        );

        $closed = $repository->find($id);
        self::assertNotNull($closed);
        self::assertSame(RepairStatus::Resolved, $closed->status);
        self::assertSame(RepairResolution::Dismissed, $closed->resolution);
        self::assertSame('root', $closed->resolvedBy);
        self::assertSame('by hand', $closed->note);
        self::assertFalse($closed->toRepresentation()['retryable']);
        self::assertNull($repository->find($id + 100));
    }

    public function testAFailedAttemptOnAClosedEntryChangesNothing(): void
    {
        $repository = $this->repository();
        $repository->record(self::request('mailstore.delete_mailbox', ['account' => 'a.example.test', 'domain' => 'example.test']));
        $id = $repository->list(new ListCriteria())->items[0]->id;
        $repository->resolve($id, RepairResolution::Dismissed, 'root', '', $this->clock->now());

        $repository->recordAttempt($id, 'mailstore.unavailable', $this->clock->now());

        $entry = $repository->find($id);
        self::assertNotNull($entry);
        self::assertSame(0, $entry->attempts);
        self::assertSame('', $entry->lastError);
        self::assertNull($entry->lastAttemptAt);
    }

    public function testTheEntryLockIsHeldWhileTheWorkRunsAndReleasedAfter(): void
    {
        $repository = $this->repository();
        $other = $this->secondConnection();
        $name = RepairQueueRepository::LOCK_PREFIX . '42';

        $heldInside = $repository->withEntryLock(
            42,
            static fn (): bool => (int) $other->selectValue('SELECT IS_FREE_LOCK(:name)', ['name' => $name]) === 0,
        );

        self::assertTrue($heldInside, 'Another connection sees the entry lock taken while the work runs.');
        self::assertSame(1, (int) $other->selectValue('SELECT IS_FREE_LOCK(:name)', ['name' => $name]));
    }

    public function testTheListIsOrderedFilteredAndPaged(): void
    {
        $repository = $this->repository();
        $repository->record(self::request('virtual.restore', [], 'alias.delete'));
        $repository->record(self::request('mailstore.delete_mailbox', [], 'account.delete'));
        $repository->record(self::request('mailstore.restore_quota', [], 'account.quota.set'));

        $newestFirst = $repository->list(new ListCriteria(order: SortDirection::Descending))->items;
        self::assertSame([3, 2, 1], array_map(static fn ($entry): int => $entry->id, $newestFirst));

        $accounts = $repository->list(ListCriteria::fromQuery(['operation' => 'account.']))->items;
        self::assertSame(['account.delete', 'account.quota.set'], array_map(static fn ($entry): string => $entry->operation, $accounts));

        $step = $repository->list(ListCriteria::fromQuery(['step' => 'virtual.restore']))->items;
        self::assertCount(1, $step);

        $repository->resolve(1, RepairResolution::Dismissed, 'root', '', $this->clock->now());
        $open = $repository->list(ListCriteria::fromQuery(['status' => 'open']));
        self::assertSame(2, $open->total);

        $page = $repository->list(new ListCriteria(page: 2, perPage: 2));
        self::assertSame(3, $page->total);
        self::assertCount(1, $page->items);
    }

    public function testAnEntrySurvivesTheRollbackOfTheOperationThatFailed(): void
    {
        // The repository on a connection of its own, as the container wires it.
        $repository = new RepairQueueRepository(
            $this->secondConnection(),
            $this->clock,
            $this->context,
            new LoggingRepairQueue($this->logger()),
        );

        $this->pdo->beginTransaction();
        $repository->record(self::request('database.remove_rows', []));
        $this->pdo->rollBack();

        self::assertSame(1, (int) $this->connection->selectValue('SELECT COUNT(*) FROM `mailadmin_repair_queue`'));
    }

    public function testWithoutTheTableTheEntryGoesToTheLogAndTheCallerIsNotFailed(): void
    {
        $this->dropIfPresent('mailadmin_repair_queue');

        $this->repository()->record(self::request('mailstore.delete_mailbox', ['account' => 'a.example.test', 'domain' => 'example.test']));

        self::assertCount(1, $this->logged);
        [$level, , $context] = $this->logged[0];
        self::assertSame('critical', $level);
        self::assertSame('mailstore.delete_mailbox', $context['step'] ?? null);
        self::assertSame(['account' => 'a.example.test', 'domain' => 'example.test'], $context['parameters'] ?? null);
    }

    public function testOverlongValuesAreClippedRatherThanRefused(): void
    {
        $this->repository()->record(new RepairRequest(
            str_repeat('o', 100),
            'virtual.restore',
            str_repeat('s', 400),
            'intended',
            'reached',
            str_repeat('r', 300),
        ));

        $entry = $this->repository()->find(1);
        self::assertNotNull($entry);
        self::assertSame(64, strlen($entry->operation));
        self::assertSame(320, strlen($entry->subject));
        self::assertSame(190, strlen($entry->reason));
    }

    public function testTheInventoryReadsTheLegacyTablesForTheReconciliation(): void
    {
        $this->createLegacyBaseline();
        $this->dropIfPresent('lists');
        $this->willCreate('lists');
        $this->pdo->exec(
            'CREATE TABLE `lists` (
                name    VARCHAR(255) NOT NULL DEFAULT "",
                tag     VARCHAR(255) NOT NULL DEFAULT "",
                address VARCHAR(255) NOT NULL DEFAULT "",
                members LONGTEXT,
                PRIMARY KEY (name)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1',
        );

        $this->pdo->exec("INSERT INTO `domain` (`domain_name`, `prefix`, `transport`) VALUES ('reconcile.test', 'reconcile.test', 'cyrus')");
        $this->pdo->exec("INSERT INTO `domain` (`domain_name`, `prefix`, `transport`) VALUES ('relay.test', 'relay.test', 'smtp:[relay.invalid]')");
        $this->pdo->exec("INSERT INTO `accountuser` (`username`, `password`, `prefix`, `domain_name`) VALUES ('anna.reconcile.test', 'x', 'reconcile.test', 'reconcile.test')");
        $this->pdo->exec("INSERT INTO `accountuser` (`username`, `password`, `prefix`, `domain_name`) VALUES ('cyrus', 'x', '', '')");
        $this->pdo->exec("INSERT INTO `virtual` (`alias`, `dest`, `username`, `status`) VALUES ('anna@reconcile.test', 'anna.reconcile.test', 'anna.reconcile.test', 1)");
        $this->pdo->exec("INSERT INTO `virtual` (`alias`, `dest`, `username`, `status`) VALUES ('team@reconcile.test', 'team@lists.reconcile.test,info.kunde.reconcile.test', 'reconcile.test', 1)");
        $this->pdo->exec("INSERT INTO `lists` (`name`, `tag`, `address`, `members`) VALUES ('team', 'team', 'team@lists.reconcile.test', '')");

        $inventory = new SqlMaintenanceInventory($this->connection);

        self::assertCount(3, $inventory->accounts(), 'The two rows here and the one of the baseline.');
        self::assertSame(['example.test', 'reconcile.test', 'relay.test'], array_map(static fn ($domain): string => $domain->name, $inventory->domains()));
        self::assertFalse($inventory->domains()[2]->isDeliveredLocally());
        self::assertCount(3, $inventory->virtualRows());
        self::assertSame(['team@lists.reconcile.test'], $inventory->listAddresses());

        $mailstore = new InMemoryMailstore();
        $mailstore->given(MailboxIdentity::fromStoredUsername('anna.reconcile.test', DomainName::fromStoredValue('reconcile.test')));
        $mailstore->given(MailboxIdentity::fromStoredUsername('info.example.test', DomainName::fromStoredValue('example.test')));
        $mailstore->givenOrphan('user/cyrus');

        $report = (new Reconcile($inventory, $mailstore, $this->clock))->handle(DomainFixture::superuser());

        self::assertCount(1, $report->findings);
        self::assertSame(FindingCategory::VirtualTargetMissing, $report->findings[0]->category);
        self::assertSame('info.kunde.reconcile.test', $report->findings[0]->related);
    }

    // -----------------------------------------------------------------

    private function repository(): RepairQueueRepository
    {
        return new RepairQueueRepository(
            $this->connection,
            $this->clock,
            $this->context,
            new LoggingRepairQueue($this->logger()),
        );
    }

    private function secondConnection(): Connection
    {
        return new Connection(new ConnectionFactory(DatabaseConfiguration::create(
            dsn: (string) (self::env(self::DISPOSABLE_DSN) ?? self::env(self::DSN)),
            user: self::env(self::USER) ?? 'root',
            password: self::env(self::PASSWORD) ?? '',
            timeoutSeconds: 3,
        )));
    }

    private function logger(): AbstractLogger
    {
        $test = $this;

        return new class ($test) extends AbstractLogger {
            public function __construct(private readonly RepairQueueIntegrationTest $test)
            {
            }

            /**
             * @param array<mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->test->remember((string) $level, (string) $message, $context);
            }
        };
    }

    /**
     * @param array<mixed> $context
     *
     * @internal for the logger above
     */
    public function remember(string $level, string $message, array $context): void
    {
        $this->logged[] = [$level, $message, $context];
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function request(string $step, array $parameters, string $operation = 'account.create'): RepairRequest
    {
        return new RepairRequest(
            $operation,
            $step,
            'user/anna.example.test',
            'intended',
            'reached',
            'test',
            $parameters,
        );
    }

    private function applyOwnMigration(): void
    {
        $this->dropIfPresent('mailadmin_repair_queue');
        $this->willCreate('mailadmin_repair_queue');

        foreach ((new MigrationLoader(dirname(__DIR__, 3) . '/migrations'))->load() as $migration) {
            if ($migration->number !== 40) {
                continue;
            }

            foreach ($migration->statements as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }
}

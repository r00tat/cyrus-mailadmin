<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\MailingList;

use Mailadmin\Application\Audit\AuditActor;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditEntryId;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\MailingList\ListDeliveryMigration;
use Mailadmin\Application\MailingList\ListDeliveryOutcome;
use Mailadmin\Application\MailingList\ListDeliveryResult;
use Mailadmin\Application\MailingList\MigrateListDelivery;
use Mailadmin\Application\MailingList\TransportDomainIsManaged;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryMailingListRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryVirtualStore;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\ListDeliveryFixture;
use Mailadmin\Tests\Support\MemoryLogger;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The maintenance command behind step 4 of the changeover (spec 6): legacy
 * list rows in `virtual` move to the transport, and `--revert` moves them back.
 *
 * The properties: only rows the classifier recognises as list rows are
 * touched, a second run changes nothing, a dry run writes nothing, every
 * changed row has an audit entry by the system actor, and a list whose address
 * the transport cannot deliver to is left alone.
 */
#[CoversClass(MigrateListDelivery::class)]
#[CoversClass(ListDeliveryMigration::class)]
#[CoversClass(ListDeliveryOutcome::class)]
final class MigrateListDeliveryTest extends TestCase
{
    private InMemoryMailingListRepository $lists;
    private InMemoryVirtualStore $virtual;
    private InMemoryDomainStore $domains;
    private InMemoryAuditTrail $trail;
    private MemoryLogger $logger;
    private ActorContext $system;

    protected function setUp(): void
    {
        $this->lists = new InMemoryMailingListRepository();
        $this->virtual = new InMemoryVirtualStore();
        $this->domains = new InMemoryDomainStore();
        $this->trail = new InMemoryAuditTrail();
        $this->logger = new MemoryLogger();
        $this->system = ActorContext::system('migrate-list-delivery');
    }

    public function testALegacyRowMovesToTheTransport(): void
    {
        $this->givenLegacyList('team@example.test');

        $result = $this->migrate()->handle($this->system);

        self::assertSame(
            [['team@example.test', 'team.example.test@maillist.invalid', '', 1]],
            $this->rows(),
        );
        self::assertSame(ListDeliveryResult::Migrated, $this->outcomeOf($result, 'team@example.test')->result);
        self::assertSame(['migrated' => 1], array_filter($result->totals()));
    }

    public function testTheLockOfTheAddressUseCasesIsTaken(): void
    {
        $this->givenLegacyList('team@example.test');

        $this->migrate()->handle($this->system);

        self::assertGreaterThanOrEqual(1, $this->domains->namespaceLocksTaken());
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->givenLegacyList('team@example.test');
        $this->migrate()->handle($this->system);
        $this->trail->forget();
        $before = $this->rows();

        $result = $this->migrate()->handle($this->system);

        self::assertSame($before, $this->rows());
        self::assertSame(ListDeliveryResult::Unchanged, $this->outcomeOf($result, 'team@example.test')->result);
        self::assertTrue($this->trail->isEmpty());
    }

    public function testADryRunReportsTheSameAndWritesNothing(): void
    {
        $this->givenLegacyList('team@example.test');
        $before = $this->rows();

        $result = $this->migrate()->handle($this->system, dryRun: true);

        self::assertSame($before, $this->rows());
        self::assertSame(ListDeliveryResult::Migrated, $this->outcomeOf($result, 'team@example.test')->result);
        self::assertTrue($result->dryRun);
        self::assertTrue($this->trail->isEmpty());
    }

    public function testRevertRestoresTheLegacyForm(): void
    {
        $this->givenLegacyList('team@example.test');
        $legacy = $this->rows();
        $this->migrate()->handle($this->system);

        $result = $this->migrate()->handle($this->system, revert: true);

        self::assertSame($legacy, $this->rows());
        self::assertSame(ListDeliveryResult::Reverted, $this->outcomeOf($result, 'team@example.test')->result);

        $again = $this->migrate()->handle($this->system, revert: true);
        self::assertSame(ListDeliveryResult::Unchanged, $this->outcomeOf($again, 'team@example.test')->result);
    }

    public function testRevertLeavesAListKeyTheUsernameColumnCannotHold(): void
    {
        // `virtual.username` is VARCHAR(50); the legacy form would be cut off.
        $address = str_repeat('a', 40) . '@example.test';
        $this->lists->given(MailingList::fromStoredValues('Long', 'long', $address, 'anna@example.test'));
        $this->virtual->givenRow($address, str_replace('@', '.', $address) . '@maillist.invalid');
        $before = $this->rows();

        $result = $this->migrate()->handle($this->system, revert: true);

        self::assertSame($before, $this->rows());
        $outcome = $this->outcomeOf($result, $address);
        self::assertSame(ListDeliveryResult::Skipped, $outcome->result);
        self::assertStringContainsString('50', $outcome->reason);
    }

    public function testAListWithoutVirtualRowIsReportedAndNotCreated(): void
    {
        $this->lists->given(MailingList::fromStoredValues('Orphan', 'orphan', 'orphan@example.test', 'anna@example.test'));

        $result = $this->migrate()->handle($this->system);

        self::assertSame([], $this->rows());
        self::assertSame(ListDeliveryResult::NoVirtualEntry, $this->outcomeOf($result, 'orphan@example.test')->result);
        self::assertSame('no virtual entry', $this->outcomeOf($result, 'orphan@example.test')->label());
    }

    public function testAnAddressWithARecipientDelimiterIsSkipped(): void
    {
        $this->givenLegacyList('team+x@example.test');
        $before = $this->rows();

        $result = $this->migrate()->handle($this->system);

        self::assertSame($before, $this->rows());
        self::assertSame(
            "skipped: address contains '+'",
            $this->outcomeOf($result, 'team+x@example.test')->label(),
        );
    }

    public function testRowsOfAnotherTypeUnderTheListAddressAreLeftAlone(): void
    {
        $this->lists->given(MailingList::fromStoredValues('Team', 'team', 'team@example.test', 'anna@example.test'));
        // An ordinary alias, not the list's routing row.
        $this->virtual->givenRow('team@example.test', 'anna.example.test', 'anna.example.test');
        $before = $this->rows();

        $result = $this->migrate()->handle($this->system);

        self::assertSame($before, $this->rows());
        self::assertSame(ListDeliveryResult::Skipped, $this->outcomeOf($result, 'team@example.test')->result);
    }

    public function testEachChangedRowHasAnAuditEntryByTheSystemActor(): void
    {
        $this->givenLegacyList('team@example.test');
        $this->givenLegacyList('crew@example.test');

        $this->migrate()->handle($this->system);

        $entries = $this->trail->withAction(MigrateListDelivery::MIGRATE);
        self::assertCount(2, $entries);
        self::assertSame('virtual', $entries[0]->objectType);
        self::assertSame('<system:migrate-list-delivery>', $entries[0]->actor);
        self::assertNull($entries[0]->actorRole);
        self::assertSame('example.test', $entries[0]->domain);
        self::assertSame('crew.example.test', $entries[0]->before['dest'] ?? null);
        self::assertSame('crew.example.test@maillist.invalid', $entries[0]->after['dest'] ?? null);

        $this->migrate()->handle($this->system, revert: true);

        self::assertCount(2, $this->trail->withAction(MigrateListDelivery::REVERT));
    }

    public function testAFailedAuditSetsTheRowBackAndIsReported(): void
    {
        $this->givenLegacyList('team@example.test');
        $before = $this->rows();

        $result = $this->migrate(RefusingAuditWriter::successes($this->trail))->handle($this->system);

        self::assertSame($before, $this->rows());
        self::assertSame(ListDeliveryResult::Failed, $this->outcomeOf($result, 'team@example.test')->result);
        self::assertTrue($result->hasFailures());
    }

    public function testTwoIdenticalLegacyRowsAreBothMigratedInOneRun(): void
    {
        $this->givenLegacyList('team@example.test');
        $this->virtual->givenRow('team@example.test', 'team.example.test', 'team.example.test');

        $result = $this->migrate()->handle($this->system);

        self::assertSame(
            [
                ['team@example.test', 'team.example.test@maillist.invalid', '', 1],
                ['team@example.test', 'team.example.test@maillist.invalid', '', 1],
            ],
            $this->rows(),
        );
        self::assertSame(2, $this->outcomeOf($result, 'team@example.test')->rows);
    }

    public function testARunAfterAPartialFailureFinishesTheRemainingRows(): void
    {
        $this->givenLegacyList('team@example.test');
        $this->virtual->givenRow('team@example.test', 'team.example.test', 'team.example.test');

        // Row 1 is migrated, the audit entry of row 2 fails and row 2 is set back.
        $failed = $this->migrate($this->refusingEntry(2))->handle($this->system);

        self::assertSame(ListDeliveryResult::Failed, $this->outcomeOf($failed, 'team@example.test')->result);
        self::assertSame(
            [
                ['team@example.test', 'team.example.test', 'team.example.test', 1],
                ['team@example.test', 'team.example.test@maillist.invalid', '', 1],
            ],
            $this->rows(),
        );

        $resumed = $this->migrate()->handle($this->system);

        self::assertSame(ListDeliveryResult::Migrated, $this->outcomeOf($resumed, 'team@example.test')->result);
        self::assertSame(1, $this->outcomeOf($resumed, 'team@example.test')->rows);
        self::assertSame(
            [
                ['team@example.test', 'team.example.test@maillist.invalid', '', 1],
                ['team@example.test', 'team.example.test@maillist.invalid', '', 1],
            ],
            $this->rows(),
        );
    }

    public function testRevertFinishesAMixtureOfBothFormsToo(): void
    {
        $this->givenLegacyList('team@example.test');
        $this->virtual->givenRow('team@example.test', 'team.example.test@maillist.invalid', '');

        $result = $this->migrate()->handle($this->system, revert: true);

        self::assertSame(ListDeliveryResult::Reverted, $this->outcomeOf($result, 'team@example.test')->result);
        self::assertSame(
            [
                ['team@example.test', 'team.example.test', 'team.example.test', 1],
                ['team@example.test', 'team.example.test', 'team.example.test', 1],
            ],
            $this->rows(),
        );
    }

    public function testAListRowNextToARowOfAnotherTypeIsSkipped(): void
    {
        $this->givenLegacyList('team@example.test');
        $this->virtual->givenRow('team@example.test', 'anna.example.test', 'anna.example.test');
        $before = $this->rows();

        $result = $this->migrate()->handle($this->system);

        self::assertSame($before, $this->rows());
        self::assertSame(
            'skipped: virtual entries of another type present',
            $this->outcomeOf($result, 'team@example.test')->label(),
        );
    }

    public function testAForwardRunRefusesWhenTheTransportDomainIsAManagedDomain(): void
    {
        $this->givenLegacyList('team@example.test');
        $this->domains->given(DomainFixture::domain('MailList.Invalid', prefix: 'maillist'));
        $before = $this->rows();

        try {
            $this->migrate()->handle($this->system, dryRun: true);
            self::fail('A forward run went ahead with a managed transport domain.');
        } catch (TransportDomainIsManaged $error) {
            self::assertStringContainsString('maillist.invalid', $error->getMessage());
        }

        self::assertSame($before, $this->rows());
        self::assertTrue($this->trail->isEmpty());
    }

    public function testRevertStillRunsWhenTheTransportDomainIsAManagedDomain(): void
    {
        $this->lists->given(MailingList::fromStoredValues('Team', 'team', 'team@example.test', 'anna@example.test'));
        $this->virtual->givenRow('team@example.test', 'team.example.test@maillist.invalid', '');
        $this->domains->given(DomainFixture::domain('maillist.invalid', prefix: 'maillist'));

        $result = $this->migrate()->handle($this->system, revert: true);

        self::assertSame(ListDeliveryResult::Reverted, $this->outcomeOf($result, 'team@example.test')->result);
    }

    public function testADomainMasterMayNotRunIt(): void
    {
        $this->expectException(AccessDenied::class);

        $this->migrate()->handle(ActorContext::domainMaster('anna', DomainName::fromString('example.test')));
    }

    public function testTheSystemActorIsAnUnauthenticatedNameInTheTrail(): void
    {
        $actor = AuditActor::of(ActorContext::system('migrate-list-delivery'));

        self::assertSame('<system:migrate-list-delivery>', $actor->username);
        self::assertSame('', $actor->roleValue());
    }

    private function migrate(?AuditWriter $audit = null): MigrateListDelivery
    {
        return new MigrateListDelivery(
            $this->lists,
            $this->virtual,
            $this->domains,
            ListDeliveryFixture::delivery(),
            $audit ?? $this->trail,
            $this->logger,
        );
    }

    /** An audit writer that refuses its `$n`th entry and accepts the others. */
    private function refusingEntry(int $n): AuditWriter
    {
        return new class ($this->trail, $n) implements AuditWriter {
            private int $seen = 0;

            public function __construct(private readonly InMemoryAuditTrail $trail, private readonly int $refuse)
            {
            }

            public function record(AuditEntry $entry): AuditEntryId
            {
                if (++$this->seen === $this->refuse) {
                    throw PersistenceError::transactionMisuse('the audit table refused the entry');
                }

                return $this->trail->record($entry);
            }
        };
    }

    private function givenLegacyList(string $address): void
    {
        $this->lists->given(MailingList::fromStoredValues('List', 'list', $address, 'anna@example.test'));
        $key = str_replace('@', '.', $address);
        $this->virtual->givenRow($address, $key, $key);
    }

    /**
     * @return list<array{string, string, string, int}>
     */
    private function rows(): array
    {
        $rows = array_map(
            static fn (VirtualRow $row): array => [$row->alias, $row->dest, $row->username, $row->status],
            $this->virtual->all(),
        );
        sort($rows);

        return $rows;
    }

    private function outcomeOf(ListDeliveryMigration $result, string $address): ListDeliveryOutcome
    {
        foreach ($result->outcomes as $outcome) {
            if ($outcome->address === $address) {
                return $outcome;
            }
        }

        self::fail('No outcome for ' . $address);
    }
}

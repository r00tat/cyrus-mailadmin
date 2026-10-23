<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\MailingList;

use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\MailingList\CreateMailingList;
use Mailadmin\Application\MailingList\DeleteMailingList;
use Mailadmin\Application\MailingList\GetMailingList;
use Mailadmin\Application\MailingList\ListMailingLists;
use Mailadmin\Application\MailingList\MailingListChanges;
use Mailadmin\Application\MailingList\MailingListFailure;
use Mailadmin\Application\MailingList\MailingListView;
use Mailadmin\Application\MailingList\UpdateMailingList;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryMailingListRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryVirtualStore;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Tests\Support\CallTrace;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\ListDeliveryFixture;
use Mailadmin\Tests\Support\MemoryLogger;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use Mailadmin\Tests\Support\RefusingRepairQueue;
use Mailadmin\Tests\Support\TracingAddressStore;
use Mailadmin\Tests\Support\TracingMailingListRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * The five mailing list use cases, against in-memory `lists`, `virtual`,
 * `domain`, audit trail and repair queue.
 *
 * What is being proved here is not that the classes call their collaborators.
 * It is the properties the package stands or falls on:
 *
 *  - **a list saved unchanged keeps the exact bytes of its member column**,
 *  - **a member may be added only if it is a mail address**, while a stranger
 *    entry that is already stored survives every save,
 *  - **a list is its address**: created as a `lists` row and a `virtual` row
 *    on the transport domain, deleted `virtual` first, and whatever cannot be
 *    finished or undone lands in the repair queue (spec 4.3),
 *  - **the policy decides, and its refusal is left to travel** — no use case
 *    catches {@see AccessDenied} or {@see ResourceNotVisible}, because the
 *    middleware turns them into `403` and `404` (U-44).
 */
#[CoversClass(CreateMailingList::class)]
#[CoversClass(DeleteMailingList::class)]
#[CoversClass(GetMailingList::class)]
#[CoversClass(ListMailingLists::class)]
#[CoversClass(UpdateMailingList::class)]
final class MailingListUseCaseTest extends TestCase
{
    /** The stored form of a legacy row: a separator this application would not write. */
    private const string LEGACY_MEMBERS = 'anna@example.test;  bernd@example.test ;|/usr/bin/processor';

    private const string LEGACY = 'legacy@example.test';

    private InMemoryMailingListRepository $repository;
    private InMemoryVirtualStore $virtual;
    private InMemoryDomainStore $domains;
    private InMemoryAuditTrail $trail;
    private InMemoryRepairQueue $repairs;
    private ?RepairQueue $repairQueue = null;
    private MemoryLogger $logger;
    private ?AuditWriter $audit = null;
    private ?MailingListRepository $lists = null;
    private ?AddressStore $addresses = null;
    private ActorContext $superuser;
    private ActorContext $domainMaster;

    protected function setUp(): void
    {
        $this->repository = new InMemoryMailingListRepository();
        $this->virtual = new InMemoryVirtualStore();
        $this->domains = new InMemoryDomainStore();
        $this->domains->given(DomainFixture::domain('example.test'));
        $this->domains->given(DomainFixture::domain('other.test', prefix: 'other.test'));
        $this->trail = new InMemoryAuditTrail();
        $this->repairs = new InMemoryRepairQueue();
        $this->logger = new MemoryLogger();
        $this->superuser = ActorContext::superuser('root');
        $this->domainMaster = ActorContext::domainMaster('anna', DomainName::fromString('example.test'));
    }

    // -----------------------------------------------------------------
    // The property the package document names: nothing is damaged.
    // -----------------------------------------------------------------

    public function testALegacyRowSavedWithoutChangingItsMembersKeepsThoseBytesExactly(): void
    {
        $this->givenLegacyList();

        $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), null);

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers(self::LEGACY));
    }

    public function testAMemberLatin1CannotHoldIsReportedAtItsPositionAndNothingIsWritten(): void
    {
        $this->givenLegacyList();

        try {
            $this->update()->handle(
                $this->superuser,
                self::LEGACY,
                new MailingListChanges(members: ['anna@example.test', 'Party 🎉 <party@example.test>']),
                null,
            );

            self::fail('A member the latin1 column cannot hold was accepted.');
        } catch (MailingListFailure $failure) {
            self::assertSame(422, $failure->status);
            self::assertSame('list.member_outside_latin1', $failure->errorCode);
            self::assertSame(['body.members.1' => 'list.member_outside_latin1'], $failure->errors);
        }

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers(self::LEGACY));
    }

    public function testATagLatin1CannotHoldIsReportedOnTheTagAndNothingIsWritten(): void
    {
        $this->givenLegacyList();

        try {
            $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'Team 🎉'), null);

            self::fail('A tag the latin1 column cannot hold was accepted.');
        } catch (MailingListFailure $failure) {
            self::assertSame(422, $failure->status);
            self::assertSame(['body.tag' => 'list.tag_outside_latin1'], $failure->errors);
        }

        self::assertSame(0, $this->repository->writeCount());
    }

    public function testResendingExactlyTheMembersThatWereReadAlsoKeepsThoseBytes(): void
    {
        $this->givenLegacyList();

        $read = $this->get()->handle($this->superuser, self::LEGACY);

        // The round trip a form makes: read the parsed members, send them back
        // untouched. The separator the row used is not the one this application
        // writes, and it still has to survive.
        $this->update()->handle(
            $this->superuser,
            self::LEGACY,
            new MailingListChanges(members: $read->members->members()),
            null,
        );

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers(self::LEGACY));
    }

    public function testAChangedMembershipIsRewrittenOnePerLine(): void
    {
        $this->givenLegacyList();

        $this->update()->handle(
            $this->superuser,
            self::LEGACY,
            new MailingListChanges(members: ['anna@example.test', 'carla@example.test']),
            null,
        );

        self::assertSame("anna@example.test\ncarla@example.test", $this->repository->storedMembers(self::LEGACY));
    }

    public function testAFieldTheRequestDidNotMentionIsNotTouched(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle(
            $this->superuser,
            self::LEGACY,
            new MailingListChanges(name: 'renamed-list'),
            null,
        );

        self::assertSame('renamed-list', $updated->name->toString());
        self::assertSame(self::LEGACY, $updated->address);
        self::assertSame('legacy', $updated->tag);
        self::assertSame(self::LEGACY_MEMBERS, $updated->members->toStoredValue());
        self::assertSame('renamed-list', $this->repository->find(EmailAddress::parse(self::LEGACY))?->name->toString());
    }

    public function testANewDisplayNameWithAControlCharacterIsRefused(): void
    {
        $this->givenLegacyList();

        try {
            $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(name: "two\nlines"), null);

            self::fail('A display name with a line break was accepted.');
        } catch (MailingListFailure $failure) {
            self::assertSame(422, $failure->status);
            self::assertSame(['body.name' => 'list.invalid_name'], $failure->errors);
        }
    }

    public function testAnEmptyMemberArrayClearsTheListAndIsNotMistakenForAbsence(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(members: []), null);

        self::assertSame('', $updated->members->toStoredValue());
    }

    public function testAnUpdateTouchesNeitherVirtualNorTheAddressAndIsAudited(): void
    {
        $this->givenLegacyList();
        $row = $this->virtual->givenRow(self::LEGACY, 'legacy.example.test@maillist.invalid');

        $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), null);

        self::assertSame([], $this->virtual->operations());
        self::assertSame([$row], $this->virtual->all());

        $entries = $this->trail->all();
        self::assertCount(1, $entries);
        self::assertSame('list.update', $entries[0]->action);
        self::assertSame('list', $entries[0]->objectType);
        self::assertSame(self::LEGACY, $entries[0]->objectId);
        self::assertSame('legacy', $entries[0]->before['tag'] ?? null);
        self::assertSame('crew', $entries[0]->after['tag'] ?? null);
        self::assertSame('example.test', $entries[0]->domain);
    }

    public function testAnUpdateWhoseAuditEntryFailsIsSetBack(): void
    {
        $this->givenLegacyList();
        $this->audit = RefusingAuditWriter::successes($this->trail);

        try {
            $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), null);
            self::fail('An unaudited change went through.');
        } catch (PersistenceError) {
        }

        self::assertSame('legacy', $this->repository->find(EmailAddress::parse(self::LEGACY))?->tag);
    }

    public function testAnUpdateWhoseSetBackAndRepairEntryBothFailStillReportsTheAuditFailure(): void
    {
        $this->givenLegacyList();
        $this->audit = RefusingAuditWriter::successes($this->trail);
        $this->repository->failReplaceAfter(1, PersistenceError::transactionMisuse('set back refused'));
        $this->repairQueue = $queue = new RefusingRepairQueue();

        try {
            $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), null);
            self::fail('An unaudited change went through.');
        } catch (PersistenceError $error) {
            // The audit failure travels, not the one of the repair queue.
            self::assertStringNotContainsString('repair queue refused', $error->getMessage());
            self::assertStringNotContainsString('set back refused', $error->getMessage());
        }

        self::assertSame(1, $queue->attempts());
        self::assertNotSame([], $this->logger->recordsAt([LogLevel::ERROR]));
    }

    public function testAnUpdateRunsUnderTheNamespaceLock(): void
    {
        $this->givenLegacyList();

        $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), null);

        self::assertSame(1, $this->domains->namespaceLocksTaken());
    }

    public function testAnUpdateLogsNoAddressBelowError(): void
    {
        $this->givenLegacyList();

        $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), null);

        self::assertNotSame([], $this->logger->records());
        $this->assertNoAddressLoggedBelowError(self::LEGACY);
    }

    // -----------------------------------------------------------------
    // Members
    // -----------------------------------------------------------------

    public function testAMemberThatIsNotAnAddressCannotBeAdded(): void
    {
        $this->givenLegacyList();

        try {
            $this->update()->handle(
                $this->superuser,
                self::LEGACY,
                new MailingListChanges(members: ['anna@example.test', '|/bin/sh -c whoami']),
                null,
            );

            self::fail('A pipe entry was accepted into a column that becomes an aliases file.');
        } catch (MailingListFailure $failure) {
            self::assertSame(422, $failure->status);
            self::assertSame('list.invalid_member', $failure->errorCode);
            self::assertSame(['body.members' => 'list.invalid_member'], $failure->errors);
        }

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers(self::LEGACY));
    }

    public function testTheStrangerEntryThatIsAlreadyStoredDoesNotBlockAnEditOfTheSameList(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle(
            $this->superuser,
            self::LEGACY,
            new MailingListChanges(members: [
                'anna@example.test',
                'bernd@example.test',
                '|/usr/bin/processor',
                'carla@example.test',
            ]),
            null,
        );

        self::assertSame(['|/usr/bin/processor'], $updated->members->unusableMembers());
        self::assertContains('carla@example.test', $updated->members->members());
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function testCreatingWritesTheListRowAndTheTransportRowInVirtual(): void
    {
        $list = $this->create()->handle(
            $this->superuser,
            'Team',
            'team',
            'team@example.test',
            ['anna@example.test'],
        );

        self::assertSame('Team', $list->name->toString());
        self::assertSame('anna@example.test', $this->repository->storedMembers('team@example.test'));
        self::assertEquals(
            [new VirtualRow('team@example.test', 'team.example.test@maillist.invalid', '', 1)],
            $this->virtual->all(),
        );
        self::assertSame(1, $this->domains->namespaceLocksTaken());
    }

    public function testTheAddressIsLowerCasedBeforeAnythingIsChecked(): void
    {
        $this->repository->given(MailingList::fromStoredValues('team', '', 'team@example.test', ''));

        $this->expectConflict(fn () => $this->create()->handle($this->superuser, 'x', '', 'Team@Example.TEST', []));

        $list = $this->create()->handle($this->superuser, 'Crew', '', 'Crew@Example.TEST', []);

        self::assertSame('crew@example.test', $list->address);
        self::assertSame('crew@example.test', $this->virtual->all()[0]->alias);
        self::assertSame('crew.example.test@maillist.invalid', $this->virtual->all()[0]->dest);
    }

    public function testAnAddressWithARecipientDelimiterIsRefused(): void
    {
        try {
            $this->create()->handle($this->superuser, 'Team', '', 'team+x@example.test', []);
            self::fail('A list address with an extension was accepted.');
        } catch (MailingListFailure $failure) {
            self::assertSame(422, $failure->status);
            self::assertSame('list.address_has_extension', $failure->errorCode);
            self::assertSame(['body.address' => 'list.address_has_extension'], $failure->errors);
        }

        $this->assertNothingWritten();
    }

    public function testAnAddressInADomainThatIsNotManagedIsRefused(): void
    {
        try {
            $this->create()->handle($this->superuser, 'Team', '', 'team@unknown.test', []);
            self::fail('A list in an unmanaged domain was accepted.');
        } catch (MailingListFailure $failure) {
            self::assertSame(422, $failure->status);
            self::assertSame('list.unknown_domain', $failure->errorCode);
            self::assertSame(['body.address' => 'list.unknown_domain'], $failure->errors);
        }

        $this->assertNothingWritten();
    }

    public function testAnAddressInListsIsTaken(): void
    {
        $this->givenLegacyList();

        $this->expectConflict(fn () => $this->create()->handle($this->superuser, 'other-name', '', self::LEGACY, []));

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers(self::LEGACY));
        $this->assertNothingWritten();
    }

    public function testAnAddressInVirtualIsTakenWhateverItsType(): void
    {
        $this->virtual->givenRow('team@example.test', 'info.example.test', 'info.example.test');

        $this->expectConflict(fn () => $this->create()->handle($this->superuser, 'Team', '', 'team@example.test', []));

        $this->assertNothingWritten();
    }

    public function testADifferentAddressWithTheSameListKeyIsTaken(): void
    {
        $this->repository->given(MailingList::fromStoredValues('dotted', '', 'team.x@example.test', ''));
        $this->domains->given(DomainFixture::domain('x.example.test', prefix: 'x.example.test'));

        $this->expectConflict(fn () => $this->create()->handle($this->superuser, 'other', '', 'team@x.example.test', []));

        $this->assertNothingWritten();
    }

    public function testTheSameDisplayNameOnAnotherAddressIsNotAConflict(): void
    {
        $this->givenLegacyList();

        $list = $this->create()->handle($this->superuser, 'legacy-list', '', 'other@example.test', []);

        self::assertSame('other@example.test', $list->address);
    }

    public function testCreatingWritesListsBeforeVirtual(): void
    {
        $trace = $this->traced();

        $this->create()->handle($this->superuser, 'Team', '', 'team@example.test', []);

        self::assertSame(['lists.add team@example.test', 'virtual.insert team@example.test'], $trace->entries());
    }

    public function testWhenVirtualFailsTheListRowIsRemovedAgain(): void
    {
        $this->virtual->failNextInsert(PersistenceError::transactionMisuse('virtual refused'));

        try {
            $this->create()->handle($this->superuser, 'Team', '', 'team@example.test', []);
            self::fail('A failed virtual insert was reported as a success.');
        } catch (PersistenceError) {
        }

        self::assertNull($this->repository->find(EmailAddress::parse('team@example.test')));
        self::assertSame([], $this->virtual->all());
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testWhenTheListRowCannotBeRemovedEitherItIsARepairEntry(): void
    {
        $this->virtual->failNextInsert(PersistenceError::transactionMisuse('virtual refused'));
        $this->repository->failNextRemove(PersistenceError::transactionMisuse('lists refused'));

        try {
            $this->create()->handle($this->superuser, 'Team', '', 'team@example.test', []);
            self::fail('A failed virtual insert was reported as a success.');
        } catch (PersistenceError $error) {
            // The original failure travels, not the one of the compensation.
            self::assertStringContainsString('virtual refused', $error->getMessage());
        }

        self::assertSame(['list.create/lists.remove'], $this->repairs->steps());
        $entry = $this->repairs->entries()[0];
        self::assertSame('team@example.test', $entry->subject);
        self::assertSame(['address' => 'team@example.test', 'domain' => 'example.test'], $entry->parameters);
        self::assertStringNotContainsString('lists refused', $entry->reason);
    }

    public function testWhenTheRepairEntryForTheListRowFailsTheOriginalFailureStillTravels(): void
    {
        $this->virtual->failNextInsert(PersistenceError::transactionMisuse('virtual refused'));
        $this->repository->failNextRemove(PersistenceError::transactionMisuse('lists refused'));
        $this->repairQueue = $queue = new RefusingRepairQueue();

        try {
            $this->create()->handle($this->superuser, 'Team', '', 'team@example.test', []);
            self::fail('A failed virtual insert was reported as a success.');
        } catch (PersistenceError $error) {
            self::assertStringContainsString('virtual refused', $error->getMessage());
        }

        self::assertSame(1, $queue->attempts());
        self::assertNotSame([], $this->logger->recordsAt([LogLevel::ERROR]));
    }

    public function testWhenTheVirtualRowCannotBeSetBackTheListRowStays(): void
    {
        $this->audit = RefusingAuditWriter::successes($this->trail);
        $this->virtual->failNextRemove(PersistenceError::transactionMisuse('virtual remove refused'));

        try {
            $this->create()->handle($this->superuser, 'Team', '', 'team@example.test', []);
            self::fail('An unaudited creation went through.');
        } catch (PersistenceError) {
        }

        // The routing row is still there, so its list must be too: removing
        // it would turn a delivered list into a bounce.
        self::assertCount(1, $this->virtual->rowsWithAlias('team@example.test'));
        self::assertNotNull($this->repository->find(EmailAddress::parse('team@example.test')));
        self::assertSame(['list.create/virtual.restore'], $this->repairs->steps());
    }

    public function testCreatingLogsNoAddressBelowError(): void
    {
        $this->create()->handle($this->superuser, 'Team', '', 'team@example.test', []);

        self::assertNotSame([], $this->logger->records());
        $this->assertNoAddressLoggedBelowError('team@example.test');
    }

    public function testCreatingIsAuditedWithTheVirtualRowAsAChild(): void
    {
        $this->create()->handle($this->superuser, 'Team', 'team', 'team@example.test', ['anna@example.test']);

        $entries = $this->trail->all();
        self::assertCount(2, $entries);

        self::assertSame('list.create', $entries[0]->action);
        self::assertSame('list', $entries[0]->objectType);
        self::assertSame('team@example.test', $entries[0]->objectId);
        self::assertSame('example.test', $entries[0]->domain);
        self::assertNull($entries[0]->parentId);
        self::assertNull($entries[0]->before);

        self::assertSame('list.create', $entries[1]->action);
        self::assertSame('virtual', $entries[1]->objectType);
        self::assertSame('team@example.test', $entries[1]->objectId);
        self::assertSame($entries[0]->id, $entries[1]->parentId);
        self::assertSame('team.example.test@maillist.invalid', $entries[1]->after['dest'] ?? null);
    }

    public function testACreationWhoseAuditEntryFailsLeavesNothingBehind(): void
    {
        $this->audit = RefusingAuditWriter::successes($this->trail);

        try {
            $this->create()->handle($this->superuser, 'Team', '', 'team@example.test', []);
            self::fail('An unaudited creation went through.');
        } catch (PersistenceError) {
        }

        self::assertNull($this->repository->find(EmailAddress::parse('team@example.test')));
        self::assertSame([], $this->virtual->all());
    }

    // -----------------------------------------------------------------
    // Deleting
    // -----------------------------------------------------------------

    public function testDeletingRemovesVirtualBeforeLists(): void
    {
        $this->givenTransportList();
        $trace = $this->traced();

        $this->delete()->handle($this->superuser, 'team@example.test', null);

        self::assertSame(['virtual.remove team@example.test', 'lists.remove team@example.test'], $trace->entries());
        self::assertNull($this->repository->find(EmailAddress::parse('team@example.test')));
        self::assertSame([], $this->virtual->all());
        self::assertSame(1, $this->domains->namespaceLocksTaken());
    }

    public function testDeletingAlsoRemovesALegacyListRowButNoRowOfAnotherType(): void
    {
        $this->repository->given(MailingList::fromStoredValues('Team', '', 'team@example.test', ''));
        $this->virtual->givenRow('team@example.test', 'team.example.test', 'team.example.test');
        $alias = $this->virtual->givenRow('team@example.test', 'info.example.test', 'info.example.test');

        $this->delete()->handle($this->superuser, 'team@example.test', null);

        self::assertSame([$alias], $this->virtual->all());
        self::assertNull($this->repository->find(EmailAddress::parse('team@example.test')));
    }

    public function testWhenListsCannotBeRemovedTheDeletionStandsWithARepairEntry(): void
    {
        $this->givenTransportList();
        $this->repository->failNextRemove(PersistenceError::transactionMisuse('lists refused'));

        $this->delete()->handle($this->superuser, 'team@example.test', null);

        self::assertSame([], $this->virtual->all());
        self::assertSame(['list.delete/lists.remove'], $this->repairs->steps());
        self::assertSame(
            ['address' => 'team@example.test', 'domain' => 'example.test'],
            $this->repairs->entries()[0]->parameters,
        );
        self::assertCount(2, $this->trail->all());
    }

    public function testWhenEvenTheRepairEntryFailsTheDeletionStillStands(): void
    {
        $this->givenTransportList();
        $this->repository->failNextRemove(PersistenceError::transactionMisuse('lists refused'));
        $this->repairQueue = $queue = new RefusingRepairQueue();

        $this->delete()->handle($this->superuser, 'team@example.test', null);

        self::assertSame([], $this->virtual->all());
        self::assertSame(1, $queue->attempts());
        self::assertCount(2, $this->logger->recordsAt([LogLevel::ERROR]));
    }

    public function testDeletingLogsNoAddressBelowError(): void
    {
        $this->repository->given(MailingList::fromStoredValues('Team', '', 'team@example.test', ''));
        $this->virtual->givenRow('team@example.test', 'team.example.test@maillist.invalid');
        $this->virtual->givenRow('team@example.test', 'info.example.test', 'info.example.test');

        $this->delete()->handle($this->superuser, 'team@example.test', null);

        self::assertNotSame([], $this->logger->recordsAt([LogLevel::NOTICE]));
        $this->assertNoAddressLoggedBelowError('team@example.test');
    }

    public function testDeletingIsAuditedWithTheVirtualRowAsAChild(): void
    {
        $this->givenTransportList();

        $this->delete()->handle($this->superuser, 'team@example.test', null);

        $entries = $this->trail->all();
        self::assertCount(2, $entries);

        self::assertSame('list.delete', $entries[0]->action);
        self::assertSame('list', $entries[0]->objectType);
        self::assertSame('team@example.test', $entries[0]->objectId);
        self::assertNull($entries[0]->parentId);
        self::assertNull($entries[0]->after);
        self::assertSame('Team', $entries[0]->before['name'] ?? null);

        self::assertSame('list.delete', $entries[1]->action);
        self::assertSame('virtual', $entries[1]->objectType);
        self::assertSame($entries[0]->id, $entries[1]->parentId);
    }

    public function testADeletionWhoseAuditEntryFailsPutsVirtualBackAndKeepsTheList(): void
    {
        $this->givenTransportList();
        $before = $this->virtual->all();
        $this->audit = RefusingAuditWriter::successes($this->trail);

        try {
            $this->delete()->handle($this->superuser, 'team@example.test', null);
            self::fail('An unaudited deletion went through.');
        } catch (PersistenceError) {
        }

        self::assertEquals($before, $this->virtual->all());
        self::assertNotNull($this->repository->find(EmailAddress::parse('team@example.test')));
    }

    // -----------------------------------------------------------------
    // Not found
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatDoNotResolve(): iterable
    {
        yield 'never existed' => ['no-such-list@example.test'];
        // A value no row could carry answers exactly like an address that is
        // merely absent — otherwise the shape of the refusal tells a caller
        // which of the two they guessed.
        yield 'not an address' => ['no such list'];
        yield 'empty' => [''];
    }

    #[DataProvider('addressesThatDoNotResolve')]
    public function testAnUnresolvableAddressIsTheFrameworksOwnNotFound(string $address): void
    {
        $this->givenLegacyList();

        foreach ([
            function () use ($address): void {
                $this->get()->handle($this->superuser, $address);
            },
            function () use ($address): void {
                $this->update()->handle($this->superuser, $address, new MailingListChanges(tag: 'x'), null);
            },
            function () use ($address): void {
                $this->delete()->handle($this->superuser, $address, null);
            },
        ] as $call) {
            try {
                $call();
                self::fail('An unresolvable address did not produce a refusal.');
            } catch (MailingListFailure $failure) {
                self::assertSame(404, $failure->status);
                self::assertSame('request.not_found', $failure->errorCode);
            }
        }
    }

    // -----------------------------------------------------------------
    // Concurrency (U-19)
    // -----------------------------------------------------------------

    public function testAMatchingIfMatchLetsTheWriteThrough(): void
    {
        $this->givenLegacyList();

        $etag = MailingListView::etag($this->get()->handle($this->superuser, self::LEGACY));

        $updated = $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), $etag);

        self::assertSame('crew', $updated->tag);
    }

    public function testAStaleIfMatchIsRefusedRatherThanOverwriting(): void
    {
        $this->givenLegacyList();

        $stale = MailingListView::etag($this->get()->handle($this->superuser, self::LEGACY));

        $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), null);

        foreach ([
            function () use ($stale): void {
                $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'other'), $stale);
            },
            function () use ($stale): void {
                $this->delete()->handle($this->superuser, self::LEGACY, $stale);
            },
        ] as $call) {
            try {
                $call();
                self::fail('A stale If-Match was accepted.');
            } catch (MailingListFailure $failure) {
                self::assertSame(412, $failure->status);
                self::assertSame('request.precondition_failed', $failure->errorCode);
            }
        }

        self::assertSame('crew', $this->get()->handle($this->superuser, self::LEGACY)->tag);
    }

    public function testAStarMatchesAnythingThatExists(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle($this->superuser, self::LEGACY, new MailingListChanges(tag: 'crew'), '*');

        self::assertSame('crew', $updated->tag);
    }

    // -----------------------------------------------------------------
    // Listing and authorisation
    // -----------------------------------------------------------------

    public function testTheListingIsPagedSortedAndFiltered(): void
    {
        $this->repository->given(
            MailingList::fromStoredValues('a-list', 'team', 'a@example.test', 'anna@example.test'),
            MailingList::fromStoredValues('b-list', 'crew', 'b@example.test', 'bernd@example.test'),
            MailingList::fromStoredValues('c-list', 'team', 'c@example.test', 'anna@example.test'),
        );

        $page = $this->listing()->handle($this->superuser, new ListCriteria(
            page: 1,
            perPage: 2,
            sort: 'name',
            order: SortDirection::Descending,
        ));

        self::assertSame(3, $page->total);
        self::assertSame(['c-list', 'b-list'], array_map(
            static fn (MailingList $list): string => $list->name->toString(),
            $page->items,
        ));

        $filtered = $this->listing()->handle($this->superuser, new ListCriteria(filters: ['tag' => 'team']));

        self::assertSame(2, $filtered->total);

        $byMember = $this->listing()->handle(
            $this->superuser,
            new ListCriteria(filters: ['member' => 'bernd@example.test']),
        );

        self::assertSame(1, $byMember->total);
    }

    public function testASortFieldOutsideTheAllowlistIsAnErrorAndNotADefault(): void
    {
        $this->givenLegacyList();

        $this->expectException(CriteriaError::class);

        // CLAUDE.md rule 2 in its smallest form: the old system pasted whatever
        // it had into the ORDER BY and nothing ever told it.
        $this->listing()->handle($this->superuser, new ListCriteria(sort: 'members'));
    }

    public function testADomainMasterListsOnlyTheListsOfTheirDomains(): void
    {
        $this->givenLegacyList();
        $this->repository->given(MailingList::fromStoredValues('foreign', '', 'team@other.test', ''));

        $page = $this->listing()->handle($this->domainMaster, new ListCriteria());

        self::assertSame([self::LEGACY], array_map(static fn (MailingList $list): string => $list->address, $page->items));
    }

    public function testADomainMasterManagesTheListsOfTheirOwnDomain(): void
    {
        $this->givenTransportList();

        self::assertSame('Team', $this->get()->handle($this->domainMaster, 'team@example.test')->name->toString());

        $this->update()->handle($this->domainMaster, 'team@example.test', new MailingListChanges(tag: 'crew'), null);
        $this->delete()->handle($this->domainMaster, 'team@example.test', null);

        $created = $this->create()->handle($this->domainMaster, 'New', '', 'new@example.test', []);

        self::assertSame('new@example.test', $created->address);
        self::assertNull($this->repository->find(EmailAddress::parse('team@example.test')));
    }

    /**
     * @return iterable<string, array{callable(self, ActorContext): mixed}>
     */
    public static function operationsOnAForeignList(): iterable
    {
        yield 'get' => [static fn (self $test, ActorContext $actor): mixed
            => $test->get()->handle($actor, 'team@other.test')];
        yield 'update' => [static fn (self $test, ActorContext $actor): mixed
            => $test->update()->handle($actor, 'team@other.test', new MailingListChanges(tag: 'x'), null)];
        yield 'delete' => [static function (self $test, ActorContext $actor): mixed {
            $test->delete()->handle($actor, 'team@other.test', null);

            return null;
        }];
    }

    /**
     * @param callable(self, ActorContext): mixed $operation
     */
    #[DataProvider('operationsOnAForeignList')]
    public function testAListOfAForeignDomainIsNotVisible(callable $operation): void
    {
        $this->repository->given(MailingList::fromStoredValues('foreign', 'f', 'team@other.test', ''));
        $this->virtual->givenRow('team@other.test', 'team.other.test@maillist.invalid');

        try {
            $operation($this, $this->domainMaster);
            self::fail('A foreign list was reachable.');
        } catch (ResourceNotVisible) {
        }

        self::assertSame(0, $this->repository->writeCount());
        self::assertSame([], $this->virtual->operations());
    }

    public function testCreatingInAForeignDomainIsForbidden(): void
    {
        $this->expectException(AccessDenied::class);

        try {
            $this->create()->handle($this->domainMaster, 'Team', '', 'team@other.test', []);
        } finally {
            $this->assertNothingWritten();
        }
    }

    /**
     * @return iterable<string, array{callable(self, ActorContext): mixed}>
     */
    public static function everyOperation(): iterable
    {
        yield 'list' => [static fn (self $test, ActorContext $actor): mixed
            => $test->listing()->handle($actor, new ListCriteria())];
        yield 'get' => [static fn (self $test, ActorContext $actor): mixed
            => $test->get()->handle($actor, self::LEGACY)];
        yield 'create' => [static fn (self $test, ActorContext $actor): mixed
            => $test->create()->handle($actor, 'new-list', '', 'new@example.test', [])];
        yield 'update' => [static fn (self $test, ActorContext $actor): mixed
            => $test->update()->handle($actor, self::LEGACY, new MailingListChanges(tag: 'x'), null)];
        yield 'delete' => [static function (self $test, ActorContext $actor): mixed {
            $test->delete()->handle($actor, self::LEGACY, null);

            return null;
        }];
    }

    /**
     * @param callable(self, ActorContext): mixed $operation
     */
    #[DataProvider('everyOperation')]
    public function testASuperuserIsNotRefused(callable $operation): void
    {
        $this->givenLegacyList();

        $operation($this, $this->superuser);

        $this->addToAssertionCount(1);
    }

    // -----------------------------------------------------------------

    private function givenLegacyList(): void
    {
        $this->repository->given(MailingList::fromStoredValues(
            'legacy-list',
            'legacy',
            self::LEGACY,
            self::LEGACY_MEMBERS,
        ));
    }

    private function givenTransportList(): void
    {
        $this->repository->given(MailingList::fromStoredValues('Team', 'team', 'team@example.test', 'anna@example.test'));
        $this->virtual->givenRow('team@example.test', 'team.example.test@maillist.invalid');
    }

    private function traced(): CallTrace
    {
        $trace = new CallTrace();
        $this->lists = new TracingMailingListRepository($this->repository, $trace);
        $this->addresses = new TracingAddressStore($this->virtual, $trace);

        return $trace;
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectConflict(callable $call): void
    {
        try {
            $call();
            self::fail('A taken address was not refused.');
        } catch (MailingListFailure $failure) {
            self::assertSame(409, $failure->status);
            self::assertSame('list.address_taken', $failure->errorCode);
        }
    }

    private function assertNothingWritten(): void
    {
        self::assertSame(0, $this->repository->writeCount());
        self::assertSame([], $this->virtual->operations());
        self::assertTrue($this->trail->isEmpty());
    }

    private function auditWriter(): AuditWriter
    {
        return $this->audit ?? $this->trail;
    }

    private function listRepository(): MailingListRepository
    {
        return $this->lists ?? $this->repository;
    }

    private function addressStore(): AddressStore
    {
        return $this->addresses ?? $this->virtual;
    }

    private function rows(): RowWriter
    {
        return new RowWriter($this->addressStore(), $this->auditWriter(), $this->repairQueue(), $this->logger);
    }

    private function listing(): ListMailingLists
    {
        return new ListMailingLists($this->listRepository());
    }

    private function get(): GetMailingList
    {
        return new GetMailingList($this->listRepository());
    }

    private function create(): CreateMailingList
    {
        return new CreateMailingList(
            $this->listRepository(),
            $this->addressStore(),
            $this->domains,
            ListDeliveryFixture::delivery(),
            $this->auditWriter(),
            $this->rows(),
            $this->repairQueue(),
            $this->logger,
        );
    }

    private function update(): UpdateMailingList
    {
        return new UpdateMailingList(
            $this->listRepository(),
            $this->domains,
            $this->auditWriter(),
            $this->repairQueue(),
            $this->logger,
        );
    }

    private function delete(): DeleteMailingList
    {
        return new DeleteMailingList(
            $this->listRepository(),
            ListDeliveryFixture::reader($this->addressStore(), $this->listRepository()),
            $this->domains,
            $this->addressStore(),
            $this->auditWriter(),
            $this->rows(),
            $this->repairQueue(),
            $this->logger,
        );
    }

    private function repairQueue(): RepairQueue
    {
        return $this->repairQueue ?? $this->repairs;
    }

    /**
     * Addresses are in the audit trail; below `error`, a log line names none
     * (CLAUDE.md, logging — like every other use case).
     */
    private function assertNoAddressLoggedBelowError(string $address): void
    {
        foreach ($this->logger->recordsAt([LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING]) as $record) {
            self::assertStringNotContainsString(
                $address,
                (string) json_encode($record),
                sprintf('The %s line "%s" names the address.', $record['level'], $record['message']),
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\MailingList;

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
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\InMemoryMailingListRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The five mailing list use cases, against the in-memory repository.
 *
 * What is being proved here is not that the classes call their collaborators.
 * It is the three properties the package stands or falls on:
 *
 *  - **a list saved unchanged keeps the exact bytes of its member column**,
 *  - **a member may be added only if it is a mail address**, while a stranger
 *    entry that is already stored survives every save,
 *  - **the policy decides, and its refusal is left to travel** — no use case
 *    catches {@see AccessDenied}, because the middleware turns it into `403`
 *    (U-44).
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

    private InMemoryMailingListRepository $repository;
    private ActorContext $superuser;
    private ActorContext $domainMaster;

    protected function setUp(): void
    {
        $this->repository = new InMemoryMailingListRepository();
        $this->superuser = ActorContext::superuser('root');
        $this->domainMaster = ActorContext::domainMaster('anna', DomainName::fromString('example.test'));
    }

    // -----------------------------------------------------------------
    // The property the package document names: nothing is damaged.
    // -----------------------------------------------------------------

    public function testALegacyRowSavedWithoutChangingItsMembersKeepsThoseBytesExactly(): void
    {
        $this->givenLegacyList();

        $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(tag: 'crew'),
        );

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers('legacy-list'));
    }

    public function testResendingExactlyTheMembersThatWereReadAlsoKeepsThoseBytes(): void
    {
        $this->givenLegacyList();

        $read = $this->get()->handle($this->superuser, 'legacy-list');

        // The round trip a form makes: read the parsed members, send them back
        // untouched. The separator the row used is not the one this application
        // writes, and it still has to survive.
        $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(members: $read->members->members()),
        );

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers('legacy-list'));
    }

    public function testAChangedMembershipIsRewrittenInTheFormTheConfigurationRunExpects(): void
    {
        $this->givenLegacyList();

        $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(members: ['anna@example.test', 'carla@example.test']),
        );

        self::assertSame(
            'anna@example.test,carla@example.test',
            $this->repository->storedMembers('legacy-list'),
        );
    }

    public function testAFieldTheRequestDidNotMentionIsNotTouched(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(address: 'other@example.test'),
        );

        self::assertSame('other@example.test', $updated->address);
        self::assertSame('legacy', $updated->tag);
        self::assertSame(self::LEGACY_MEMBERS, $updated->members->toStoredValue());
    }

    public function testAnEmptyMemberArrayClearsTheListAndIsNotMistakenForAbsence(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(members: []),
        );

        self::assertSame('', $updated->members->toStoredValue());
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
                'legacy-list',
                new MailingListChanges(members: ['anna@example.test', '|/bin/sh -c whoami']),
            );

            self::fail('A pipe entry was accepted into a column that becomes an aliases file.');
        } catch (MailingListFailure $failure) {
            self::assertSame(422, $failure->status);
            self::assertSame('list.invalid_member', $failure->errorCode);
            self::assertSame(['body.members' => 'list.invalid_member'], $failure->errors);
        }

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers('legacy-list'));
    }

    public function testTheStrangerEntryThatIsAlreadyStoredDoesNotBlockAnEditOfTheSameList(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(members: [
                'anna@example.test',
                'bernd@example.test',
                '|/usr/bin/processor',
                'carla@example.test',
            ]),
        );

        self::assertSame(['|/usr/bin/processor'], $updated->members->unusableMembers());
        self::assertContains('carla@example.test', $updated->members->members());
    }

    // -----------------------------------------------------------------
    // Creating, deleting, conflicts
    // -----------------------------------------------------------------

    public function testCreatingAListStoresItAndReportsWhatWasStored(): void
    {
        $list = $this->create()->handle(
            $this->superuser,
            'team-example',
            'team',
            'team-list@example.test',
            ['anna@example.test'],
        );

        self::assertSame('team-example', $list->name->toString());
        self::assertSame('anna@example.test', $this->repository->storedMembers('team-example'));
    }

    public function testANameThatIsTakenIsAConflictAndNotAnOverwrite(): void
    {
        $this->givenLegacyList();

        try {
            $this->create()->handle($this->superuser, 'legacy-list', '', 'other@example.test', []);

            self::fail('An existing list was silently overwritten.');
        } catch (MailingListFailure $failure) {
            self::assertSame(409, $failure->status);
            self::assertSame('list.already_exists', $failure->errorCode);
        }

        self::assertSame(self::LEGACY_MEMBERS, $this->repository->storedMembers('legacy-list'));
    }

    public function testDeletingRemovesTheRow(): void
    {
        $this->givenLegacyList();

        $this->delete()->handle($this->superuser, 'legacy-list');

        self::assertNull($this->repository->storedMembers('legacy-list'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatDoNotResolve(): iterable
    {
        yield 'never existed' => ['no-such-list'];
        // A name no row could carry answers exactly like a name that is merely
        // absent — otherwise the shape of the refusal tells a caller which of
        // the two they guessed.
        yield 'could never be a key' => ['no such list'];
        yield 'empty' => [''];
    }

    #[DataProvider('namesThatDoNotResolve')]
    public function testAnUnresolvableNameIsTheFrameworksOwnNotFound(string $name): void
    {
        $this->givenLegacyList();

        foreach ([
            function () use ($name): void {
                $this->get()->handle($this->superuser, $name);
            },
            function () use ($name): void {
                $this->update()->handle($this->superuser, $name, new MailingListChanges(tag: 'x'));
            },
            function () use ($name): void {
                $this->delete()->handle($this->superuser, $name);
            },
        ] as $call) {
            try {
                $call();
                self::fail('An unresolvable name did not produce a refusal.');
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

        $etag = MailingListView::etag($this->get()->handle($this->superuser, 'legacy-list'));

        $updated = $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(tag: 'crew'),
            $etag,
        );

        self::assertSame('crew', $updated->tag);
    }

    public function testAStaleIfMatchIsRefusedRatherThanOverwriting(): void
    {
        $this->givenLegacyList();

        $stale = MailingListView::etag($this->get()->handle($this->superuser, 'legacy-list'));

        $this->update()->handle($this->superuser, 'legacy-list', new MailingListChanges(tag: 'crew'));

        foreach ([
            function () use ($stale): void {
                $this->update()->handle(
                    $this->superuser,
                    'legacy-list',
                    new MailingListChanges(tag: 'other'),
                    $stale,
                );
            },
            function () use ($stale): void {
                $this->delete()->handle($this->superuser, 'legacy-list', $stale);
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

        self::assertSame('crew', $this->get()->handle($this->superuser, 'legacy-list')->tag);
    }

    public function testAnAbsentIfMatchIsAcceptedBecauseTheHeaderIsOptional(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(tag: 'crew'),
            null,
        );

        self::assertSame('crew', $updated->tag);
    }

    public function testAStarMatchesAnythingThatExists(): void
    {
        $this->givenLegacyList();

        $updated = $this->update()->handle(
            $this->superuser,
            'legacy-list',
            new MailingListChanges(tag: 'crew'),
            '*',
        );

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

        $page = $this->lists()->handle($this->superuser, new ListCriteria(
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

        $filtered = $this->lists()->handle($this->superuser, new ListCriteria(filters: ['tag' => 'team']));

        self::assertSame(2, $filtered->total);

        $byMember = $this->lists()->handle(
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
        $this->lists()->handle($this->superuser, new ListCriteria(sort: 'members'));
    }

    /**
     * @return iterable<string, array{callable(self, ActorContext): mixed}>
     */
    public static function everyOperation(): iterable
    {
        yield 'list' => [static fn (self $test, ActorContext $actor): mixed
            => $test->lists()->handle($actor, new ListCriteria())];
        yield 'get' => [static fn (self $test, ActorContext $actor): mixed
            => $test->get()->handle($actor, 'legacy-list')];
        yield 'create' => [static fn (self $test, ActorContext $actor): mixed
            => $test->create()->handle($actor, 'new-list', '', 'new@example.test', [])];
        yield 'update' => [static fn (self $test, ActorContext $actor): mixed
            => $test->update()->handle($actor, 'legacy-list', new MailingListChanges(tag: 'x'))];
        yield 'delete' => [static function (self $test, ActorContext $actor): mixed {
            $test->delete()->handle($actor, 'legacy-list');

            return null;
        }];
    }

    /**
     * @param callable(self, ActorContext): mixed $operation
     */
    #[DataProvider('everyOperation')]
    public function testADomainMasterIsRefusedAndTheRefusalIsNotCaughtHere(callable $operation): void
    {
        $this->givenLegacyList();

        // U-58: list management is superuser-only for now. The exception is
        // deliberately not translated into anything by the use case — the
        // middleware makes it a 403, in one place for all eight packages (U-44).
        $this->expectException(AccessDenied::class);

        $operation($this, $this->domainMaster);
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
            'legacy@example.test',
            self::LEGACY_MEMBERS,
        ));
    }

    private function lists(): ListMailingLists
    {
        return new ListMailingLists($this->repository);
    }

    private function get(): GetMailingList
    {
        return new GetMailingList($this->repository);
    }

    private function create(): CreateMailingList
    {
        return new CreateMailingList($this->repository, new NullLogger());
    }

    private function update(): UpdateMailingList
    {
        return new UpdateMailingList($this->repository, new NullLogger());
    }

    private function delete(): DeleteMailingList
    {
        return new DeleteMailingList($this->repository, new NullLogger());
    }
}

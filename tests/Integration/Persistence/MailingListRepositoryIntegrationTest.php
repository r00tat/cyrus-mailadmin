<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\MailingList\MailingListName;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\SqlMailingListRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * {@see SqlMailingListRepository} against a real MariaDB.
 *
 * The table is created here rather than taken from the fixtures, with the
 * definition the baseline schema declares — `MyISAM`, `latin1`, `name` as the
 * primary key, `members` as a `LONGTEXT` that may be `NULL`. That matters for
 * two of the tests below: the primary key is what makes a duplicate a conflict
 * rather than an overwrite, and the nullable column is the case an in-memory
 * double cannot really have.
 *
 * The centrepiece is the acceptance criterion the package document names by
 * name: **read an existing row, save it unchanged, compare byte for byte.**
 */
#[CoversClass(SqlMailingListRepository::class)]
final class MailingListRepositoryIntegrationTest extends DatabaseTestCase
{
    private SqlMailingListRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropIfPresent('lists');
        $this->willCreate('lists');

        // The definition from `docker/db/init/00-baseline-schema.sql`, which in
        // turn follows what the analysis recorded of the production table.
        $this->pdo->exec(
            'CREATE TABLE `lists` (
                name    VARCHAR(255) NOT NULL DEFAULT "",
                tag     VARCHAR(255) NOT NULL DEFAULT "",
                address VARCHAR(255) NOT NULL DEFAULT "",
                members LONGTEXT,
                PRIMARY KEY (name)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1',
        );

        $this->repository = new SqlMailingListRepository($this->connection);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function storedMemberTexts(): iterable
    {
        // Every plausible shape of the column, because which one the 22 existing
        // rows use was never captured — and none of them may be damaged.
        yield 'comma separated' => ['anna@example.test,bernd@example.test'];
        yield 'comma and space' => ['anna@example.test, bernd@example.test'];
        yield 'semicolon and spaces' => ['anna@example.test ;  bernd@example.test'];
        yield 'newline separated' => ["anna@example.test\nbernd@example.test"];
        yield 'trailing separator' => ['anna@example.test,'];
        yield 'a pipe among the members' => ['anna@example.test,|/usr/bin/processor'];
        yield 'empty string' => [''];
        yield 'null' => [null];
    }

    #[DataProvider('storedMemberTexts')]
    public function testAnExistingRowSavedUnchangedIsIdenticalByteForByte(?string $members): void
    {
        $this->insertRaw('legacy-list', 'legacy', 'legacy@example.test', $members);

        $read = $this->repository->find(MailingListName::fromStoredValue('legacy-list'));

        self::assertNotNull($read);

        // Exactly what an administrator does when they correct a tag and leave
        // the membership alone.
        $this->repository->replace($read->withTag('corrected'));

        self::assertSame($members, $this->rawMembers('legacy-list'));
        self::assertSame('corrected', $this->repository->find(
            MailingListName::fromStoredValue('legacy-list'),
        )?->tag);
    }

    #[DataProvider('storedMemberTexts')]
    public function testResendingTheParsedMembersIsAlsoIdenticalByteForByte(?string $members): void
    {
        $this->insertRaw('legacy-list', 'legacy', 'legacy@example.test', $members);

        $read = $this->repository->find(MailingListName::fromStoredValue('legacy-list'));

        self::assertNotNull($read);

        $this->repository->replace($read->withMembers($read->members->members()));

        self::assertSame($members, $this->rawMembers('legacy-list'));
    }

    public function testAChangedMembershipIsWrittenCommaSeparated(): void
    {
        $this->insertRaw('legacy-list', 'legacy', 'legacy@example.test', "anna@example.test\n");

        $read = $this->repository->find(MailingListName::fromStoredValue('legacy-list'));

        self::assertNotNull($read);

        $this->repository->replace($read->withMembers(['anna@example.test', 'bernd@example.test']));

        self::assertSame('anna@example.test,bernd@example.test', $this->rawMembers('legacy-list'));
    }

    public function testAddingTellsAConflictApartFromAFailure(): void
    {
        $list = MailingList::create('team-example', 'team', 'team@example.test', ['anna@example.test']);

        self::assertTrue($this->repository->add($list));

        // The primary key refuses the second one, and the repository reports that
        // as a conflict rather than letting a driver exception become a 500.
        self::assertFalse($this->repository->add($list));
        self::assertSame(1, $this->countRows());
    }

    public function testRemovingReportsWhetherThereWasAnything(): void
    {
        $this->insertRaw('legacy-list', '', 'legacy@example.test', null);

        self::assertTrue($this->repository->remove(MailingListName::fromStoredValue('legacy-list')));
        self::assertFalse($this->repository->remove(MailingListName::fromStoredValue('legacy-list')));
    }

    public function testAMissingRowIsNullAndNotAnException(): void
    {
        self::assertNull($this->repository->find(MailingListName::fromStoredValue('no-such-list')));
    }

    public function testTheListingPagesSortsAndFilters(): void
    {
        $this->insertRaw('a-list', 'team', 'a@example.test', 'anna@example.test');
        $this->insertRaw('b-list', 'crew', 'b@example.test', 'bernd@example.test');
        $this->insertRaw('c-list', 'team', 'c@example.test', 'anna@example.test');

        $actor = ActorContext::superuser('root');

        $page = $this->repository->list($actor, new ListCriteria(
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

        self::assertSame(2, $this->repository->list($actor, new ListCriteria(filters: ['tag' => 'team']))->total);
        self::assertSame(
            1,
            $this->repository->list($actor, new ListCriteria(filters: ['member' => 'bernd@']))->total,
        );
    }

    public function testAnUnknownSortFieldIsRefusedByTheDatabaseLayerToo(): void
    {
        $this->expectException(CriteriaError::class);

        $this->repository->list(ActorContext::superuser('root'), new ListCriteria(sort: 'members'));
    }

    /**
     * The scope predicate is a no-op today, because `list.*` is superuser-only
     * (U-58). This test exists so that the derived `list_domain` column is
     * exercised against a real MariaDB rather than only in principle: if
     * `SUBSTRING_INDEX` or the derived table were wrong, the widening described
     * in `ListPolicy` would fail on the day somebody made it, not today.
     */
    public function testTheScopePredicateNarrowsByTheDomainInTheAddress(): void
    {
        $this->insertRaw('a-list', '', 'a@example.test', null);
        $this->insertRaw('b-list', '', 'b@other.test', null);
        $this->insertRaw('c-list', '', 'not-an-address', null);

        $scoped = ActorContext::domainMaster('anna', DomainName::fromString('example.test'));

        $page = $this->repository->list($scoped, new ListCriteria());

        self::assertSame(1, $page->total);
        self::assertSame('a-list', $page->items[0]->name->toString());
    }

    // -----------------------------------------------------------------

    private function insertRaw(string $name, string $tag, string $address, ?string $members): void
    {
        $this->connection->execute(
            'INSERT INTO `lists` (`name`, `tag`, `address`, `members`) VALUES (:name, :tag, :address, :members)',
            ['name' => $name, 'tag' => $tag, 'address' => $address, 'members' => $members],
        );
    }

    private function rawMembers(string $name): ?string
    {
        /** @var mixed $value */
        $value = $this->connection->selectValue(
            'SELECT `members` FROM `lists` WHERE `name` = :name',
            ['name' => $name],
        );

        return is_string($value) ? $value : null;
    }

    private function countRows(): int
    {
        return (int) $this->connection->selectValue('SELECT COUNT(*) FROM `lists`');
    }
}

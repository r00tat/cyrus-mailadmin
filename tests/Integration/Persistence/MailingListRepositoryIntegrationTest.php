<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
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
 * production definition the baseline schema declares — `MyISAM`, `latin1`, an
 * AUTO_INCREMENT `id` as the only key, `members` as a `NOT NULL` `TEXT`. That
 * matters for the tests below: with no unique index, two lists may share a
 * `name` or even an `address`, and `find` has to say which row wins.
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

        // The definition from `docker/db/init/00-baseline-schema.sql`, which is
        // the production DDL.
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

        $this->repository = new SqlMailingListRepository($this->connection);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storedMemberTexts(): iterable
    {
        // Every plausible shape of the column — none of them may be damaged.
        yield 'comma separated' => ['anna@example.test,bernd@example.test'];
        yield 'comma and space' => ['anna@example.test, bernd@example.test'];
        yield 'semicolon and spaces' => ['anna@example.test ;  bernd@example.test'];
        yield 'newline separated' => ["anna@example.test\nbernd@example.test"];
        yield 'trailing separator' => ['anna@example.test,'];
        yield 'a pipe among the members' => ['anna@example.test,|/usr/bin/processor'];
        yield 'empty string' => [''];
    }

    #[DataProvider('storedMemberTexts')]
    public function testAnExistingRowSavedUnchangedIsIdenticalByteForByte(string $members): void
    {
        $this->insertRaw('legacy-list', 'legacy', 'legacy@example.test', $members);

        $read = $this->repository->find(EmailAddress::parse('legacy@example.test'));

        self::assertNotNull($read);

        // Exactly what an administrator does when they correct a tag and leave
        // the membership alone.
        $this->repository->replace($read->withTag('corrected'));

        self::assertSame($members, $this->rawMembers('legacy@example.test'));
        self::assertSame('corrected', $this->repository->find(
            EmailAddress::parse('legacy@example.test'),
        )?->tag);
    }

    #[DataProvider('storedMemberTexts')]
    public function testResendingTheParsedMembersIsAlsoIdenticalByteForByte(string $members): void
    {
        $this->insertRaw('legacy-list', 'legacy', 'legacy@example.test', $members);

        $read = $this->repository->find(EmailAddress::parse('legacy@example.test'));

        self::assertNotNull($read);

        $this->repository->replace($read->withMembers($read->members->members()));

        self::assertSame($members, $this->rawMembers('legacy@example.test'));
    }

    public function testAChangedMembershipIsWrittenOnePerLine(): void
    {
        $this->insertRaw('legacy-list', 'legacy', 'legacy@example.test', "anna@example.test\n");

        $read = $this->repository->find(EmailAddress::parse('legacy@example.test'));

        self::assertNotNull($read);

        $this->repository->replace($read->withMembers(['anna@example.test', 'bernd@example.test']));

        self::assertSame("anna@example.test\nbernd@example.test", $this->rawMembers('legacy@example.test'));
    }

    public function testAddingStoresARowThatFindReadsBackByAddress(): void
    {
        $this->repository->add(
            MailingList::create('Team-Example', 'team', 'team@example.test', ['anna@example.test']),
        );

        $found = $this->repository->find(EmailAddress::parse('team@example.test'));

        self::assertNotNull($found);
        self::assertSame('Team-Example', $found->name->toString());
        self::assertSame('anna@example.test', $this->rawMembers('team@example.test'));
    }

    public function testTwoListsMayShareADisplayName(): void
    {
        $this->repository->add(MailingList::create('Team', '', 'one@example.test', []));
        $this->repository->add(MailingList::create('Team', '', 'two@example.test', []));

        self::assertSame(2, $this->countRows());
        self::assertSame(
            'one@example.test',
            $this->repository->find(EmailAddress::parse('one@example.test'))?->address,
        );
        self::assertSame(
            'two@example.test',
            $this->repository->find(EmailAddress::parse('two@example.test'))?->address,
        );
    }

    public function testFindPrefersTheSmallestIdWhenAnAddressExistsTwice(): void
    {
        $this->insertRaw('first', '', 'dup@example.test', 'a@example.test');
        $this->insertRaw('second', '', 'dup@example.test', 'b@example.test');

        self::assertSame(
            'first',
            $this->repository->find(EmailAddress::parse('dup@example.test'))?->name->toString(),
        );
    }

    public function testReplaceChangesNameTagAndMembersButNotTheAddress(): void
    {
        $this->insertRaw('old', 'old-tag', 'team@example.test', 'anna@example.test');

        $this->repository->replace(
            MailingList::fromStoredValues('New-Name', 'new-tag', 'team@example.test', 'bernd@example.test'),
        );

        $after = $this->repository->find(EmailAddress::parse('team@example.test'));

        self::assertNotNull($after);
        self::assertSame('New-Name', $after->name->toString());
        self::assertSame('new-tag', $after->tag);
        self::assertSame('bernd@example.test', $this->rawMembers('team@example.test'));
        self::assertSame(1, $this->countRows());
    }

    public function testExistsWithKeyFindsADifferentAddressWithTheSameKey(): void
    {
        $this->insertRaw('dotted', '', 'a.b@c.test', '');

        // `a@b.c.test` and `a.b@c.test` both become `a.b.c.test`.
        self::assertTrue($this->repository->existsWithKey('a.b.c.test'));
        self::assertFalse($this->repository->existsWithKey('other.c.test'));
    }

    public function testRemovingDeletesEveryRowOfTheAddressAndReportsWhetherThereWasAnything(): void
    {
        $this->insertRaw('one', '', 'legacy@example.test', '');
        $this->insertRaw('two', '', 'legacy@example.test', '');
        $this->insertRaw('keep', '', 'keep@example.test', '');

        self::assertTrue($this->repository->remove(EmailAddress::parse('legacy@example.test')));
        self::assertFalse($this->repository->remove(EmailAddress::parse('legacy@example.test')));
        self::assertSame(1, $this->countRows());
    }

    public function testAMissingRowIsNullAndNotAnException(): void
    {
        self::assertNull($this->repository->find(EmailAddress::parse('nobody@example.test')));
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
        $this->insertRaw('a-list', '', 'a@example.test', '');
        $this->insertRaw('b-list', '', 'b@other.test', '');
        $this->insertRaw('c-list', '', 'not-an-address', '');

        $scoped = ActorContext::domainMaster('anna', DomainName::fromString('example.test'));

        $page = $this->repository->list($scoped, new ListCriteria());

        self::assertSame(1, $page->total);
        self::assertSame('a-list', $page->items[0]->name->toString());
    }

    // -----------------------------------------------------------------

    private function insertRaw(string $name, string $tag, string $address, string $members): void
    {
        $this->connection->execute(
            'INSERT INTO `lists` (`name`, `tag`, `address`, `members`) VALUES (:name, :tag, :address, :members)',
            ['name' => $name, 'tag' => $tag, 'address' => $address, 'members' => $members],
        );
    }

    private function rawMembers(string $address): ?string
    {
        /** @var mixed $value */
        $value = $this->connection->selectValue(
            'SELECT `members` FROM `lists` WHERE `address` = :address',
            ['address' => $address],
        );

        return is_string($value) ? $value : null;
    }

    private function countRows(): int
    {
        return (int) $this->connection->selectValue('SELECT COUNT(*) FROM `lists`');
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\InMemoryMailingListRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The double keeps the promises of the SQL repository that its users rely on:
 * identity is the address, a display name may repeat, the first of two rows
 * with one address wins, and a key collision is visible across addresses.
 */
#[CoversClass(InMemoryMailingListRepository::class)]
final class InMemoryMailingListRepositoryTest extends TestCase
{
    private InMemoryMailingListRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryMailingListRepository();
    }

    public function testAListIsFoundByItsAddress(): void
    {
        $this->repository->add(MailingList::create('Team', 'tag', 'team@example.test', ['a@example.test']));

        self::assertSame(
            'Team',
            $this->repository->find(EmailAddress::parse('team@example.test'))?->name->toString(),
        );
        self::assertNull($this->repository->find(EmailAddress::parse('other@example.test')));
    }

    public function testTwoListsMayShareADisplayName(): void
    {
        $this->repository->add(MailingList::create('Team', '', 'one@example.test', []));
        $this->repository->add(MailingList::create('Team', '', 'two@example.test', []));

        self::assertNotNull($this->repository->find(EmailAddress::parse('one@example.test')));
        self::assertNotNull($this->repository->find(EmailAddress::parse('two@example.test')));
    }

    public function testTheFirstRowWinsWhenAnAddressExistsTwice(): void
    {
        $this->repository->given(
            MailingList::fromStoredValues('first', '', 'dup@example.test', ''),
            MailingList::fromStoredValues('second', '', 'dup@example.test', ''),
        );

        self::assertSame(
            'first',
            $this->repository->find(EmailAddress::parse('dup@example.test'))?->name->toString(),
        );
    }

    public function testReplaceKeepsTheAddressAndChangesTheRest(): void
    {
        $this->repository->add(MailingList::create('Old', 'old', 'team@example.test', []));

        $this->repository->replace(MailingList::create('New', 'new', 'team@example.test', ['a@example.test']));

        $found = $this->repository->find(EmailAddress::parse('team@example.test'));

        self::assertSame('New', $found?->name->toString());
        self::assertSame('a@example.test', $this->repository->storedMembers('team@example.test'));
    }

    public function testTheListKeyCollidesAcrossDifferentAddresses(): void
    {
        $this->repository->add(MailingList::create('Dotted', '', 'a.b@c.test', []));

        self::assertTrue($this->repository->existsWithKey('a.b.c.test'));
        self::assertFalse($this->repository->existsWithKey('a.b.d.test'));
    }

    public function testAddressesInDomainNamesTheListsOfExactlyThatDomainLowerCased(): void
    {
        $this->repository->given(
            MailingList::fromStoredValues('one', '', 'Team@Example.Test', ''),
            MailingList::fromStoredValues('two', '', ' crew@example.test ', ''),
            MailingList::fromStoredValues('sub', '', 'news@sub.example.test', ''),
            MailingList::fromStoredValues('suffix', '', 'x@notexample.test', ''),
            MailingList::fromStoredValues('other', '', 'team@other.test', ''),
        );

        self::assertSame(
            ['team@example.test', 'crew@example.test'],
            $this->repository->addressesInDomain(DomainName::fromString('example.test')),
        );
    }

    public function testRemoveDeletesEveryRowOfTheAddress(): void
    {
        $this->repository->given(
            MailingList::fromStoredValues('one', '', 'dup@example.test', ''),
            MailingList::fromStoredValues('two', '', 'dup@example.test', ''),
            MailingList::fromStoredValues('keep', '', 'keep@example.test', ''),
        );

        self::assertTrue($this->repository->remove(EmailAddress::parse('dup@example.test')));
        self::assertFalse($this->repository->remove(EmailAddress::parse('dup@example.test')));
        self::assertNotNull($this->repository->find(EmailAddress::parse('keep@example.test')));
    }

    public function testAListWhoseNameHasASpaceIsStillListed(): void
    {
        $this->repository->given(
            MailingList::fromStoredValues('Team Example', '', 'a@example.test', ''),
            MailingList::fromStoredValues('plain', '', 'b@example.test', ''),
        );

        $page = $this->repository->list(ActorContext::superuser('root'), new ListCriteria());

        self::assertSame(2, $page->total);
        self::assertEqualsCanonicalizing(
            ['Team Example', 'plain'],
            array_map(static fn (MailingList $list): string => $list->name->toString(), $page->items),
        );
    }

    public function testAddressesCompareWithoutRegardToCase(): void
    {
        $this->repository->add(MailingList::create('Team', '', 'Team@Example.test', []));

        self::assertNotNull($this->repository->find(EmailAddress::parse('team@example.test')));
        self::assertTrue($this->repository->existsWithKey('team.example.test'));
    }
}

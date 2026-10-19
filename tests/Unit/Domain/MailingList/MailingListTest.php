<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\MailingList;

use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\MailingList\MailingList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailingList::class)]
final class MailingListTest extends TestCase
{
    public function testAStoredRowIsAcceptedAsItIs(): void
    {
        $list = MailingList::fromStoredValues(
            'team-example',
            'team',
            'team-list@example.test',
            'anna@example.test,bernd@example.test',
        );

        self::assertSame('team-example', $list->name->toString());
        self::assertSame('team', $list->tag);
        self::assertSame('team-list@example.test', $list->address);
        self::assertSame(['anna@example.test', 'bernd@example.test'], $list->members->members());
        self::assertTrue($list->addressIsUsable());
        self::assertSame('example.test', $list->domain()?->toString());
    }

    public function testAStoredRowWithAnAddressThatIsNotOneIsShownAndFlagged(): void
    {
        // U-22 again: `lists` is written by another tool, and a row it wrote is
        // not this application's to reject or to correct.
        $list = MailingList::fromStoredValues('legacy', '', 'not-an-address', null);

        self::assertSame('not-an-address', $list->address);
        self::assertFalse($list->addressIsUsable());
        self::assertNull($list->domain());
        self::assertNull($list->members->toStoredValue());
    }

    public function testCreatingAListRunsEveryValueThroughTheStrictDoor(): void
    {
        $list = MailingList::create(
            'team-example',
            ' team ',
            ' team-list@example.test ',
            ['anna@example.test'],
        );

        self::assertSame('team', $list->tag, 'Surrounding whitespace is trimmed.');
        self::assertSame('team-list@example.test', $list->address);
        self::assertSame('anna@example.test,bernd@example.test', $list
            ->withMembers(['anna@example.test', 'bernd@example.test'])
            ->members
            ->toStoredValue());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesANewListMayNotHave(): iterable
    {
        yield 'no at sign' => ['team-list'];
        yield 'nothing in front' => ['@example.test'];
        yield 'nothing behind' => ['team-list@'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 250) . '@example.test'];
    }

    #[DataProvider('addressesANewListMayNotHave')]
    public function testANewOrChangedAddressHasToBeAMailAddress(string $address): void
    {
        $this->expectException(InvalidMailingList::class);

        MailingList::create('team-example', '', $address, []);
    }

    public function testAnUnchangedMembershipLeavesTheObjectAloneEntirely(): void
    {
        $list = MailingList::fromStoredValues(
            'team-example',
            'team',
            'team-list@example.test',
            'anna@example.test, bernd@example.test',
        );

        $same = $list->withMembers(['anna@example.test', 'bernd@example.test']);

        // Identity, not equality: the whole point is that nothing was rebuilt,
        // so the stored text — spacing included — is still the one to be written.
        self::assertSame($list, $same);
        self::assertSame('anna@example.test, bernd@example.test', $same->members->toStoredValue());
    }

    public function testChangingTheTagDoesNotTouchTheMemberText(): void
    {
        $list = MailingList::fromStoredValues(
            'team-example',
            'team',
            'team-list@example.test',
            "anna@example.test\nbernd@example.test",
        );

        $changed = $list->withTag('crew');

        self::assertSame('crew', $changed->tag);
        self::assertSame("anna@example.test\nbernd@example.test", $changed->members->toStoredValue());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tagsWithAControlCharacter(): iterable
    {
        // Every one of these is stripped by PHP's `trim()`, which is why the
        // check runs before it: a tag containing them must be refused, not
        // silently tidied.
        yield 'a null byte' => ["team\x00"];
        yield 'a newline' => ["team\nexample"];
        yield 'a trailing newline' => ["team\n"];
        yield 'a tab' => ["team\tly"];
        yield 'a delete character' => ["team\x7F"];
    }

    #[DataProvider('tagsWithAControlCharacter')]
    public function testATagWithAControlCharacterIsRefused(string $tag): void
    {
        $this->expectException(InvalidMailingList::class);

        MailingList::create('team-example', $tag, 'team-list@example.test', []);
    }

    public function testATagIsCarriedAndNotInterpreted(): void
    {
        // Open point 2 of the package document: what the column means was never
        // recorded. So anything printable goes in and comes back out unchanged.
        $list = MailingList::create('team-example', 'anything at all', 'team-list@example.test', []);

        self::assertSame('anything at all', $list->tag);
    }
}

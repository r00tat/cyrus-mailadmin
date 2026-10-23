<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\MailingList;

use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\MailingList\MemberList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Taking `lists.members` apart and putting it back together, losslessly — the
 * unit test the package document asks for by name.
 */
#[CoversClass(MemberList::class)]
final class MemberListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function storedValues(): iterable
    {
        yield 'comma separated' => [
            'anna@example.test,bernd@example.test',
            ['anna@example.test', 'bernd@example.test'],
        ];

        yield 'comma and space' => [
            'anna@example.test, bernd@example.test',
            ['anna@example.test', 'bernd@example.test'],
        ];

        yield 'semicolon' => [
            'anna@example.test;bernd@example.test',
            ['anna@example.test', 'bernd@example.test'],
        ];

        yield 'newlines' => [
            "anna@example.test\nbernd@example.test\n",
            ['anna@example.test', 'bernd@example.test'],
        ];

        yield 'windows newlines' => [
            "anna@example.test\r\nbernd@example.test",
            ['anna@example.test', 'bernd@example.test'],
        ];

        yield 'trailing separator' => [
            'anna@example.test,',
            ['anna@example.test'],
        ];

        yield 'empty' => ['', []];

        yield 'nothing but separators' => [',,;', []];

        yield 'one member' => ['anna@example.test', ['anna@example.test']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('storedValues')]
    public function testTheStoredTextIsReadWhicheverSeparatorItUses(string $stored, array $expected): void
    {
        // Reading is deliberately wider than writing: guessing wrong here turns
        // one member into several or several into one, and a list that is
        // misparsed on the way in is a list that is destroyed on the way out.
        self::assertSame($expected, MemberList::fromStoredValue($stored)->members());
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('storedValues')]
    public function testSavingWithoutChangingTheMembershipReturnsTheStoredTextByteForByte(
        string $stored,
        array $expected,
    ): void {
        $list = MemberList::fromStoredValue($stored);

        $again = $list->withMembers($expected);

        self::assertSame($list, $again, 'An unchanged membership must not produce a new object.');
        self::assertSame($stored, $again->toStoredValue());
    }

    /**
     * The `NULL` case, which is not the same as the empty string: a column that
     * was `NULL` and was not edited must go back as `NULL`.
     */
    public function testANullColumnStaysNull(): void
    {
        $list = MemberList::fromStoredValue(null);

        self::assertSame([], $list->members());
        self::assertNull($list->toStoredValue());
        self::assertNull($list->withMembers([])->toStoredValue());
    }

    public function testAChangedMembershipIsRenderedOnePerLine(): void
    {
        $list = MemberList::fromStoredValue('anna@example.test');

        $changed = $list->withMembers(['anna@example.test', 'bernd@example.test']);

        self::assertNotSame($list, $changed);
        self::assertSame("anna@example.test\nbernd@example.test", $changed->toStoredValue());
        self::assertTrue($changed->isCanonical());
    }

    public function testAnUnchangedLegacyBreakListComesBackByteForByte(): void
    {
        $stored = "a@x.test<br />\nb@x.test";
        $list = MemberList::fromStoredValue($stored);

        self::assertSame(['a@x.test', 'b@x.test'], $list->members());
        self::assertSame($list, $list->withMembers(['a@x.test', 'b@x.test']));
        self::assertSame($stored, $list->toStoredValue());
        self::assertFalse($list->isCanonical());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function breakSpellings(): iterable
    {
        yield 'br' => ['<br>'];
        yield 'br slash' => ['<br/>'];
        yield 'br space slash' => ['<br />'];
        yield 'upper case' => ['<BR>'];
        yield 'encoded' => ['&lt;br&gt;'];
    }

    #[DataProvider('breakSpellings')]
    public function testEveryBreakSpellingSeparates(string $break): void
    {
        $list = MemberList::fromStoredValue('a@x.test' . $break . 'b@x.test');

        self::assertSame(['a@x.test', 'b@x.test'], $list->members());
    }

    public function testEntitiesAreDecodedBeforeTheBreaksAreSplit(): void
    {
        $list = MemberList::fromStoredValue('J&ouml;rg &lt;j@x.test&gt;&lt;br /&gt;Tom &amp; Jerry &lt;t@x.test&gt;');

        self::assertSame(['Jörg <j@x.test>', 'Tom & Jerry <t@x.test>'], $list->members());
    }

    public function testAChangedLegacyListIsWrittenCanonicalWithDecodedEntities(): void
    {
        $list = MemberList::fromStoredValue('Tom &amp; Jerry &lt;t@x.test&gt;<br />a@x.test');

        $changed = $list->withMembers(['Tom & Jerry <t@x.test>', 'a@x.test', 'b@x.test']);

        self::assertSame("Tom & Jerry <t@x.test>\na@x.test\nb@x.test", $changed->toStoredValue());
        self::assertTrue($changed->isCanonical());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function displayNamesWithSeparators(): iterable
    {
        yield 'comma' => ['"Doe, John" <j@x.test>'];
        yield 'semicolon' => ['Doe; John <j@x.test>'];
        yield 'break in the name' => ['Name<br>Foo <j@x.test>'];
        yield 'entity that would be decoded' => ['Tom &amp; Jerry <t@x.test>'];
    }

    #[DataProvider('displayNamesWithSeparators')]
    public function testANewMemberThatWouldNotReadBackAsItselfIsRefused(string $member): void
    {
        // Anything the parser would split or decode is not read back as the same
        // member, so it would silently turn into something else on the next load.
        $this->expectException(InvalidMailingList::class);

        MemberList::none()->withMembers([$member]);
    }

    public function testDeliverableAddressesTakeTheAddressPartDropInvalidOnesAndDuplicates(): void
    {
        $list = MemberList::fromStoredValue("Alice <Alice@x.test>\n|/bin/sh\nalice@X.TEST\n bob@x.test ");

        self::assertSame(['Alice@x.test', 'bob@x.test'], $list->deliverableAddresses());
    }

    public function testOrderIsPartOfTheMembership(): void
    {
        $list = MemberList::fromStoredValue('anna@example.test,bernd@example.test');

        // Not a set: the aliases file lists them in order, and reordering is a
        // change like any other.
        $reordered = $list->withMembers(['bernd@example.test', 'anna@example.test']);

        self::assertNotSame($list, $reordered);
        self::assertSame("bernd@example.test\nanna@example.test", $reordered->toStoredValue());
    }

    public function testDuplicatesAreKept(): void
    {
        // Two identical entries in an aliases file mean two deliveries. Removing
        // one silently would change behaviour nobody asked to change.
        $list = MemberList::none()->withMembers(['anna@example.test', 'anna@example.test']);

        self::assertSame(['anna@example.test', 'anna@example.test'], $list->members());
        self::assertSame(2, $list->count());
    }

    public function testEntriesAreTrimmedAndEmptyOnesDropped(): void
    {
        $list = MemberList::none()->withMembers(['  anna@example.test  ', '', '   ']);

        self::assertSame(['anna@example.test'], $list->members());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableMembers(): iterable
    {
        yield 'a pipe' => ['|/usr/bin/processor'];
        yield 'a file path' => ['/var/spool/list/archive'];
        yield 'a bare user name' => ['operator'];
        yield 'an address with nothing in front' => ['@example.test'];
        yield 'an address with nothing behind' => ['anna@'];
    }

    #[DataProvider('unusableMembers')]
    public function testAMemberBeingAddedHasToBeAMailAddress(string $member): void
    {
        // The security rule of this package: the column is rendered into an
        // aliases file, where a pipe is a command.
        $this->expectException(InvalidMailingList::class);

        MemberList::none()->withMembers([$member]);
    }

    #[DataProvider('unusableMembers')]
    public function testAMemberThatIsAlreadyStoredIsNeitherRejectedNorRemoved(string $member): void
    {
        $stored = MemberList::fromStoredValue($member . ',anna@example.test');

        // Adding somebody to a list that already contains an odd entry works —
        // the odd entry is carried along untouched.
        $changed = $stored->withMembers([$member, 'anna@example.test', 'bernd@example.test']);

        self::assertSame(
            [$member, 'anna@example.test', 'bernd@example.test'],
            $changed->members(),
        );
        self::assertSame([$member], $changed->unusableMembers());
    }

    public function testUnusableMembersAreReportedNotHidden(): void
    {
        $list = MemberList::fromStoredValue('anna@example.test,|/usr/bin/processor,operator');

        self::assertCount(3, $list->members(), 'Nothing is filtered out of the member list.');
        self::assertSame(['|/usr/bin/processor', 'operator'], $list->unusableMembers());
    }

    public function testARenderedListThatWouldNotFitTheColumnIsRefused(): void
    {
        $member = str_repeat('a', 60) . '@example.test';
        $members = array_fill(0, 1000, $member);

        $this->expectException(InvalidMailingList::class);

        // Silent truncation in a non-strict installation is how a list loses its
        // last members without anybody being told.
        MemberList::none()->withMembers($members);
    }

    public function testAnEmptyListIsEmptyWhicheverWayItWasBuilt(): void
    {
        self::assertTrue(MemberList::none()->isEmpty());
        self::assertTrue(MemberList::fromStoredValue(null)->isEmpty());
        self::assertTrue(MemberList::fromStoredValue('')->isEmpty());
        self::assertFalse(MemberList::fromStoredValue('anna@example.test')->isEmpty());
    }

    public function testClearingAListIsAChangeAndIsAllowed(): void
    {
        $list = MemberList::fromStoredValue('anna@example.test');

        $cleared = $list->withMembers([]);

        self::assertNotSame($list, $cleared);
        self::assertSame('', $cleared->toStoredValue());
        self::assertTrue($cleared->isEmpty());
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model\Virtual;

use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DestinationList::class)]
final class DestinationListTest extends TestCase
{
    public function testACommaSeparatedValueIsSplitAndTrimmed(): void
    {
        $list = DestinationList::fromLegacyString(' info.example.test , elsewhere@other.test ');

        self::assertSame(['info.example.test', 'elsewhere@other.test'], $list->all());
        self::assertSame(2, $list->count());
        self::assertSame('info.example.test', $list->first());
    }

    public function testEmptyEntriesAreDroppedRatherThanRejected(): void
    {
        $list = DestinationList::fromLegacyString('info.example.test,,');

        self::assertSame(['info.example.test'], $list->all());
    }

    public function testAnEmptyValueYieldsAnEmptyList(): void
    {
        $list = DestinationList::fromLegacyString('   ');

        self::assertTrue($list->isEmpty());
        self::assertNull($list->first());
        self::assertSame('', $list->toLegacyString());
    }

    public function testOnlyTheDestinationsThatAreAddressesAreParsed(): void
    {
        // A Cyrus user name in the dotted scheme has no "@" — it is a local
        // delivery, not an external address.
        $list = DestinationList::fromLegacyString('info.example.test,elsewhere@other.test');

        $addresses = $list->addresses();

        self::assertCount(1, $addresses);
        self::assertSame('elsewhere@other.test', $addresses[0]->toString());
    }

    public function testTheLegacyFormatSurvivesTheRoundTrip(): void
    {
        $list = DestinationList::of('info.example.test', 'elsewhere@other.test');

        self::assertSame('info.example.test,elsewhere@other.test', $list->toLegacyString());
        self::assertTrue($list->equals(
            DestinationList::fromLegacyString($list->toLegacyString()),
        ));
    }

    public function testMembershipIgnoresCase(): void
    {
        $list = DestinationList::fromLegacyString('Info.Example.Test');

        self::assertTrue($list->contains('info.example.test'));
        self::assertFalse($list->contains('other.example.test'));
    }

    public function testANewListNeedsAtLeastOneDestination(): void
    {
        $this->expectException(InvalidAddress::class);

        DestinationList::of();
    }

    public function testADestinationWithASeparatorIsRefused(): void
    {
        $this->expectException(InvalidAddress::class);

        DestinationList::of('a@example.test,b@example.test');
    }

    public function testADestinationWithWhitespaceInsideIsRefused(): void
    {
        $this->expectException(InvalidAddress::class);

        DestinationList::of('a b@example.test');
    }
}

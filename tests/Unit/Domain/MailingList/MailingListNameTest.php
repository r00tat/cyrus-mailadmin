<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\MailingList;

use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\MailingList\MailingListName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailingListName::class)]
final class MailingListNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptableNames(): iterable
    {
        yield 'a word' => ['team'];
        yield 'with a hyphen' => ['team-example'];
        yield 'with a dot' => ['team.example'];
        yield 'with an underscore' => ['team_example'];
        yield 'with digits' => ['team2026'];
    }

    #[DataProvider('acceptableNames')]
    public function testAConventionalNamePassesBothDoors(string $name): void
    {
        self::assertSame($name, MailingListName::fromString($name)->toString());
        self::assertTrue(MailingListName::fromStoredValue($name)->isConventional());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesNoRowCanCarry(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'with a space inside' => ['team example'];
        yield 'with a tab' => ["team\texample"];
        yield 'with a newline' => ["team\nexample"];
        // Both are structure in an aliases file, so neither door accepts them:
        // a name carrying one would produce a file the MTA reads differently
        // from what the form said.
        yield 'with a colon' => ['team:example'];
        yield 'too long' => [str_repeat('a', 256)];
    }

    #[DataProvider('namesNoRowCanCarry')]
    public function testANameThatCouldNeverBeAKeyIsRefusedByBothDoors(string $name): void
    {
        $refusals = 0;

        foreach ([MailingListName::fromString(...), MailingListName::fromStoredValue(...)] as $door) {
            try {
                $door($name);
            } catch (InvalidMailingList) {
                ++$refusals;
            }
        }

        self::assertSame(2, $refusals, 'Both the strict and the tolerant door have to refuse this.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unconventionalStoredNames(): iterable
    {
        yield 'leading dot' => ['.team'];
        yield 'trailing hyphen' => ['team-'];
        yield 'an at sign' => ['team@example.test'];
        yield 'a slash' => ['team/example'];
        yield 'upper and lower mixed with a star' => ['Team*Example'];
    }

    #[DataProvider('unconventionalStoredNames')]
    public function testAStoredNameThisApplicationWouldNotHandOutIsKeptAndMarked(string $name): void
    {
        // U-22: existing rows stay readable and stay visible. What they do not
        // do is become a template for new ones.
        $stored = MailingListName::fromStoredValue($name);

        self::assertSame($name, $stored->toString());
        self::assertFalse($stored->isConventional());

        $this->expectException(InvalidMailingList::class);
        MailingListName::fromString($name);
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame('team', MailingListName::fromStoredValue('  team  ')->toString());
    }

    public function testComparisonIsCaseSensitive(): void
    {
        // The column is the primary key and MariaDB compares it under a collation
        // this application does not control. Folding case here would claim two
        // names are one where the database might disagree.
        self::assertFalse(
            MailingListName::fromStoredValue('Team')->equals(MailingListName::fromStoredValue('team')),
        );
        self::assertTrue(
            MailingListName::fromStoredValue('team')->equals(MailingListName::fromStoredValue('team')),
        );
    }
}

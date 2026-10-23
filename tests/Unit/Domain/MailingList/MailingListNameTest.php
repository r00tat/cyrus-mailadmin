<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\MailingList;

use Mailadmin\Domain\Exception\DomainErrorCode;
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
        yield 'with spaces' => ['Team Example Nord'];
        yield 'with a colon' => ['Team: Vertrieb'];
        yield 'with umlauts' => ['Grüße an alle Mitarbeiter'];
        yield 'with an at sign' => ['team@example.test'];
        yield 'with punctuation' => ['Team (alt) / Archiv*'];
        yield 'leading dot' => ['.team'];
        yield 'exactly 255 characters' => [str_repeat('a', 255)];
        yield '255 characters, umlauts included' => [str_repeat('ä', 255)];
    }

    #[DataProvider('acceptableNames')]
    public function testADisplayNamePassesBothDoors(string $name): void
    {
        self::assertSame($name, MailingListName::fromString($name)->toString());
        self::assertTrue(MailingListName::fromStoredValue($name)->isConventional());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namesTheStrictDoorRefuses(): iterable
    {
        yield 'empty' => ['', DomainErrorCode::LIST_INVALID_NAME];
        yield 'whitespace only' => ['   ', DomainErrorCode::LIST_INVALID_NAME];
        yield 'with a tab' => ["team\texample", DomainErrorCode::LIST_INVALID_NAME];
        yield 'with a newline' => ["team\nexample", DomainErrorCode::LIST_INVALID_NAME];
        yield 'with a NUL byte' => ["team\0example", DomainErrorCode::LIST_INVALID_NAME];
        yield 'with DEL' => ["team\x7Fexample", DomainErrorCode::LIST_INVALID_NAME];
        yield '256 characters' => [str_repeat('a', 256), DomainErrorCode::LIST_INVALID_NAME];
        yield '256 characters, umlauts included' => [str_repeat('ä', 256), DomainErrorCode::LIST_INVALID_NAME];
        // `lists` is latin1. MariaDB refuses these in strict mode (a 500) and
        // turns them into `?` without it; neither is what the form said.
        yield 'an emoji' => ['Team 🎉', DomainErrorCode::LIST_NAME_OUTSIDE_LATIN1];
        yield 'a euro sign' => ['Team €', DomainErrorCode::LIST_NAME_OUTSIDE_LATIN1];
        yield 'a C1 control character' => ["Team \u{0085}", DomainErrorCode::LIST_NAME_OUTSIDE_LATIN1];
        yield 'not UTF-8' => ["Team \xFF", DomainErrorCode::LIST_NAME_OUTSIDE_LATIN1];
    }

    #[DataProvider('namesTheStrictDoorRefuses')]
    public function testANewNameThatCannotBeStoredAsWrittenIsRefused(string $name, string $code): void
    {
        try {
            MailingListName::fromString($name);

            self::fail('The name was accepted.');
        } catch (InvalidMailingList $error) {
            self::assertSame($code, $error->code());
        }
    }

    #[DataProvider('namesTheStrictDoorRefuses')]
    public function testAStoredNameIsNeverRefusedOnlyMarkedUnconventional(string $name, string $code): void
    {
        unset($code); // The strict door's code; the tolerant door has none.

        // The name is display text (U-22): one odd row must not make the
        // listing fail for every other row, and must come back byte for byte.
        $stored = MailingListName::fromStoredValue($name);

        self::assertSame($name, $stored->toString());
        self::assertFalse($stored->isConventional());
    }

    public function testSurroundingWhitespaceIsTrimmedForANewNameOnly(): void
    {
        self::assertSame('team', MailingListName::fromString('  team  ')->toString());
        self::assertSame('  team  ', MailingListName::fromStoredValue('  team  ')->toString());
        self::assertFalse(
            MailingListName::fromStoredValue('  team  ')->isConventional(),
            'The strict door would not hand this value back unchanged.',
        );
    }

    public function testComparisonIsCaseSensitive(): void
    {
        // MariaDB compares the column under a collation this application does
        // not control. Folding case here would claim two names are one where
        // the database might disagree.
        self::assertFalse(
            MailingListName::fromStoredValue('Team')->equals(MailingListName::fromStoredValue('team')),
        );
        self::assertTrue(
            MailingListName::fromStoredValue('team')->equals(MailingListName::fromStoredValue('team')),
        );
    }
}

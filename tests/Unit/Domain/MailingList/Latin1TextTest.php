<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\MailingList;

use Mailadmin\Domain\MailingList\Latin1Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Latin1Text::class)]
final class Latin1TextTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function storable(): iterable
    {
        yield 'empty' => [''];
        yield 'ASCII' => ['Team Nord: anna@example.test'];
        yield 'umlauts and sharp s' => ['Grüße, Jürgen'];
        yield 'the top of latin1' => ["\u{00A0}\u{00FF}"];
        // Control characters are storable; whether a field wants them is the
        // field's own rule.
        yield 'a C0 control' => ["a\tb"];
    }

    #[DataProvider('storable')]
    public function testTextLatin1HoldsWithoutLossFits(string $text): void
    {
        self::assertTrue(Latin1Text::fits($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unstorable(): iterable
    {
        yield 'an emoji' => ['Team 🎉'];
        yield 'a euro sign' => ['5 €'];
        yield 'a C1 control' => ["a\u{0085}b"];
        yield 'Cyrillic' => ['Привет'];
        yield 'not UTF-8' => ["a\xFFb"];
    }

    #[DataProvider('unstorable')]
    public function testTextLatin1CannotHoldDoesNotFit(string $text): void
    {
        self::assertFalse(Latin1Text::fits($text));
    }

    public function testLengthIsCountedInCharacters(): void
    {
        self::assertSame(5, Latin1Text::characters('Grüße'));
        self::assertSame(0, Latin1Text::characters(''));
    }
}

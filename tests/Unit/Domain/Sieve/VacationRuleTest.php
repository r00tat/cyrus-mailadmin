<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Sieve;

use Mailadmin\Domain\Exception\InvalidSieveRule;
use Mailadmin\Domain\Sieve\VacationRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VacationRule::class)]
final class VacationRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function subjectsWithControlCharacters(): iterable
    {
        yield 'CRLF and a header' => ["Out of office\r\nBcc: victim@example.org"];
        yield 'a bare LF' => ["Out\nof office"];
        yield 'a bare CR' => ["Out\rof office"];
        yield 'a NUL' => ["Out\0of office"];
        yield 'a tab' => ["Out\tof office"];
        yield 'DEL' => ["Out\x7Fof office"];
    }

    /**
     * Review finding on A3: a line break inside the quoted `:subject` is legal
     * Sieve and would reach the header of every auto-reply (U-114).
     */
    #[DataProvider('subjectsWithControlCharacters')]
    public function testASubjectWithAControlCharacterIsRefused(string $subject): void
    {
        $this->expectException(InvalidSieveRule::class);
        VacationRule::of('Bin weg.', $subject);
    }

    public function testASubjectWithUmlautsAndQuotesIsAccepted(): void
    {
        $rule = VacationRule::of('Bin weg.', '  Abwesend: „Urlaub" bis Mitte März  ');

        self::assertSame('Abwesend: „Urlaub" bis Mitte März', $rule->subject);
    }
}

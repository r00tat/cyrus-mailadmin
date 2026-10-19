<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Sieve;

use Mailadmin\Infrastructure\Mailstore\Sieve\SieveLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The tokeniser, on the lines that actually cost something when read wrongly.
 *
 * The case worth naming is {@see testAScriptCalledActiveIsNotTheActiveMarker()}.
 * `LISTSCRIPTS` answers `"name" ACTIVE`, and a client that split the line on
 * whitespace would read a script *called* `ACTIVE` as the running one — then
 * refuse to delete the wrong script, and overwrite the right one.
 */
#[CoversClass(SieveLine::class)]
final class SieveLineTest extends TestCase
{
    public function testAQuotedStringLosesItsQuotesAndKeepsItsEscapes(): void
    {
        $line = SieveLine::of('"a \\"quoted\\" name with a \\\\ in it"');

        self::assertSame(['a "quoted" name with a \\ in it'], $line->strings());
    }

    public function testALiteralIsResolvedToItsOctets(): void
    {
        $line = SieveLine::of(
            '"SIEVE" ' . SieveLine::placeholder(0),
            ["fileinto vacation\r\nregex"],
        );

        self::assertSame(['SIEVE', "fileinto vacation\r\nregex"], $line->strings());
    }

    public function testACompletionIsRecognisedAndADataLineIsNot(): void
    {
        self::assertSame('OK', SieveLine::of('OK "Completed"')->statusWord());
        self::assertSame('NO', SieveLine::of('NO (NONEXISTENT) "no such script"')->statusWord());
        self::assertSame('BYE', SieveLine::of('BYE "Try again later"')->statusWord());
        self::assertNull(SieveLine::of('"sieve" ACTIVE')->statusWord());
        self::assertNull(SieveLine::of('"OK" "not a completion"')->statusWord());
    }

    public function testTheResponseCodeIsTakenFromTheParenthesesAndUpperCased(): void
    {
        self::assertSame(
            'NONEXISTENT',
            SieveLine::of('NO (nonexistent) "no such script"')->responseCode(),
        );
        self::assertSame(
            'QUOTA/MAXSCRIPTS',
            SieveLine::of('NO (QUOTA/MAXSCRIPTS) "too many"')->responseCode(),
        );
        self::assertNull(SieveLine::of('NO "no code at all"')->responseCode());
    }

    /**
     * A code group may carry an argument, and the argument may contain the
     * closing parenthesis. Ending the group at the first `)` would leave the
     * rest of the line as stray tokens.
     */
    public function testACodeWithAQuotedArgumentIsReadWhole(): void
    {
        $line = SieveLine::of('BYE (REFERRAL "sieve://other.example)test") "moved"');

        self::assertSame('REFERRAL', $line->responseCode());
        self::assertSame('moved', $line->completionText());
    }

    public function testTheActiveMarkerIsAnAtom(): void
    {
        $line = SieveLine::of('"sieve" ACTIVE');

        self::assertSame(['sieve'], $line->strings());
        self::assertTrue($line->hasAtom('ACTIVE'));
        self::assertTrue($line->hasAtom('active'));
    }

    public function testAScriptCalledActiveIsNotTheActiveMarker(): void
    {
        $line = SieveLine::of('"ACTIVE"');

        self::assertSame(['ACTIVE'], $line->strings());
        self::assertFalse(
            $line->hasAtom('ACTIVE'),
            'a quoted script name was read as the ACTIVE marker; the wrong script '
            . 'would be treated as the running one',
        );
    }

    /**
     * A transcript never carries a literal's octets: a ManageSieve literal is a
     * Sieve script, and a Sieve script holds a vacation message.
     */
    public function testATraceShowsALiteralAsItsLengthAndNothingElse(): void
    {
        $script = "# private\r\nvacation text:\r\nIch bin im Urlaub.\r\n.\r\n;\r\n";
        $line = SieveLine::of(SieveLine::placeholder(0), [$script]);

        $trace = $line->trace();

        self::assertSame('{' . strlen($script) . '}', $trace);
        self::assertStringNotContainsString('Urlaub', $trace);
    }

    public function testATraceHasNoControlCharactersThatCouldSplitALogLine(): void
    {
        self::assertSame('OK .Completed.', SieveLine::of("OK \rCompleted\x01")->trace());
    }
}

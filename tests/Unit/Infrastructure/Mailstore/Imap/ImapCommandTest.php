<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\Imap\ImapArgument;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapCommand;
use Mailadmin\Infrastructure\Mailstore\Imap\Literal;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImapCommand::class)]
#[CoversClass(ImapArgument::class)]
#[CoversClass(Literal::class)]
final class ImapCommandTest extends TestCase
{
    public function testASimpleCommandIsOneWriteEndingInCrlf(): void
    {
        $command = ImapCommand::of('CREATE', ImapArgument::mailbox('user/anna.example.test'));

        self::assertSame(
            ["A0001 CREATE \"user/anna.example.test\"\r\n"],
            $command->parts('A0001'),
        );
        self::assertSame(0, $command->literalCount('A0001'));
    }

    public function testTheCommandNameIsUpperCasedAndChecked(): void
    {
        self::assertSame('SETQUOTA', ImapCommand::of(' setquota ')->name);

        $this->expectException(ProtocolError::class);

        ImapCommand::of('SET QUOTA');
    }

    /**
     * The legacy client wrapped everything in quotation marks and hoped. A name
     * holding a quotation mark then does not merely arrive wrong, it is
     * *reinterpreted*: the server reads the rest as further arguments, and for
     * `DELETE` that is a different mailbox.
     */
    #[DataProvider('awkwardNames')]
    public function testAnAwkwardNameSurvivesIntact(string $name, string $expected): void
    {
        $parts = ImapCommand::of('DELETE', ImapArgument::mailbox($name))->parts('A0001');

        self::assertSame($expected, $parts[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function awkwardNames(): iterable
    {
        yield 'a space' => ['Sent Items', "A0001 DELETE \"Sent Items\"\r\n"];
        yield 'a quotation mark' => ['say "hi"', "A0001 DELETE \"say \\\"hi\\\"\"\r\n"];
        yield 'a backslash' => ['back\\slash', "A0001 DELETE \"back\\\\slash\"\r\n"];
        yield 'an umlaut' => ['Entwürfe', "A0001 DELETE \"Entw&APw-rfe\"\r\n"];
        yield 'an ampersand' => ['R&D', "A0001 DELETE \"R&-D\"\r\n"];
    }

    /**
     * CR and LF cannot be escaped in a quoted string; they end the line. A
     * literal is the only correct form, and it costs one round trip.
     */
    public function testANameWithALineBreakTravelsAsALiteral(): void
    {
        $command = ImapCommand::of('DELETE', ImapArgument::text("New\r\nMail"));

        self::assertSame([
            "A0001 DELETE {9}\r\n",
            "New\r\nMail\r\n",
        ], $command->parts('A0001'));
        self::assertSame(1, $command->literalCount('A0001'));
    }

    public function testAnythingLongTravelsAsALiteralToo(): void
    {
        $long = str_repeat('a', Literal::QUOTED_MAX_LENGTH + 1);

        self::assertSame(1, ImapCommand::of('DELETE', ImapArgument::text($long))->literalCount('A0001'));
    }

    public function testArgumentsAfterALiteralContinueOnTheSamePart(): void
    {
        $command = ImapCommand::of(
            'SETQUOTA',
            ImapArgument::literal('user/anna'),
            ImapArgument::list(ImapArgument::atom('STORAGE'), ImapArgument::number(51200)),
        );

        self::assertSame([
            "A0001 SETQUOTA {9}\r\n",
            "user/anna (STORAGE 51200)\r\n",
        ], $command->parts('A0001'));
    }

    public function testAParenthesisedListIsWrittenWithoutASpaceAfterTheBracket(): void
    {
        $command = ImapCommand::of(
            'SETQUOTA',
            ImapArgument::mailbox('user/anna'),
            ImapArgument::list(ImapArgument::atom('STORAGE'), ImapArgument::number(0)),
        );

        self::assertSame(
            ["A0001 SETQUOTA \"user/anna\" (STORAGE 0)\r\n"],
            $command->parts('A0001'),
        );
    }

    public function testAnAtomThatIsNotOneIsRefusedAtTheCallSite(): void
    {
        $this->expectException(ProtocolError::class);

        ImapArgument::atom('not an atom');
    }

    // -----------------------------------------------------------------
    // Traces
    // -----------------------------------------------------------------

    /**
     * M1's definition of done: no password appears in a log. The mark travels
     * with the value, so nobody has to remember which position of which command
     * holds it.
     */
    public function testASecretArgumentIsNeverRendered(): void
    {
        $trace = ImapCommand::of(
            'SETMETADATA',
            ImapArgument::mailbox('user/anna'),
            ImapArgument::secret('hunter2'),
        )->trace('A0001');

        self::assertStringNotContainsString('hunter2', $trace);
        self::assertStringContainsString(ProtocolTrace::REDACTED, $trace);
    }

    /**
     * The second lock: even built with a plain text argument, `LOGIN` shows
     * nothing. One of the two mechanisms has to fail before a password reaches a
     * log.
     */
    public function testLoginIsRedactedEvenWhenBuiltCarelessly(): void
    {
        $trace = ImapCommand::of(
            'LOGIN',
            ImapArgument::text('cyrus'),
            ImapArgument::text('hunter2'),
        )->trace('A0001');

        self::assertSame('A0001 LOGIN <redacted> <redacted>', $trace);
    }

    /**
     * The mechanism name is public and useful; everything after it is credential
     * material.
     */
    public function testAuthenticateKeepsItsMechanismAndHidesTheRest(): void
    {
        $trace = ImapCommand::of(
            'AUTHENTICATE',
            ImapArgument::atom('PLAIN'),
            ImapArgument::secret('base64=='),
        )->trace('A0001');

        self::assertSame('A0001 AUTHENTICATE PLAIN <redacted>', $trace);
    }

    /**
     * A literal payload can be a Sieve script, and a Sieve script can hold a
     * vacation message — which is personal data. Its length is enough to debug a
     * framing bug.
     */
    public function testALiteralIsTracedAsItsLength(): void
    {
        $trace = ImapCommand::of(
            'PUTSCRIPT',
            ImapArgument::literal('vacation :days 7;'),
        )->trace('A0001');

        self::assertSame('A0001 PUTSCRIPT {17}', $trace);
    }

    public function testACommandKnowsWhetherItCarriesASecret(): void
    {
        self::assertFalse(ImapCommand::of('NOOP')->isSensitive());
        self::assertTrue(ImapCommand::of('LOGIN', ImapArgument::text('cyrus'))->isSensitive());
        self::assertTrue(
            ImapCommand::of('X', ImapArgument::list(ImapArgument::secret('s')))->isSensitive(),
        );
    }
}

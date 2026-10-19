<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\Imap\CommandResult;
use Mailadmin\Infrastructure\Mailstore\Imap\ResponseLine;
use Mailadmin\Infrastructure\Mailstore\Imap\ResponseParser;
use Mailadmin\Infrastructure\Mailstore\Imap\ResponseStatus;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseParser::class)]
#[CoversClass(CommandResult::class)]
#[CoversClass(ResponseStatus::class)]
final class ResponseParserTest extends TestCase
{
    public function testAQuotaLineSplitsIntoItsMailboxAndItsNumbers(): void
    {
        $line = new ResponseLine('* QUOTA "user/anna.example.test" (STORAGE 1234 51200)');

        self::assertSame(
            ['*', 'QUOTA', 'user/anna.example.test', ['STORAGE', '1234', '51200']],
            $line->tokens(),
        );
    }

    /**
     * A tokenizer that treats `\` as an escape outside a quoted string loses the
     * flag entirely — and `\Noselect` without its backslash reads as a folder
     * name.
     */
    public function testAFlagKeepsItsBackslash(): void
    {
        $line = new ResponseLine('* LIST (\\HasNoChildren \\Noselect) "/" "user/anna"');

        self::assertSame(
            ['*', 'LIST', ['\\HasNoChildren', '\\Noselect'], '/', 'user/anna'],
            $line->tokens(),
        );
    }

    public function testNilBecomesNullAndNotTheWordNil(): void
    {
        self::assertSame(['*', 'X', null], (new ResponseLine('* X NIL'))->tokens());
    }

    public function testAnEscapedQuoteInsideAQuotedStringSurvives(): void
    {
        self::assertSame(
            ['*', 'X', 'say "hi"', 'back\\slash'],
            (new ResponseLine('* X "say \\"hi\\"" "back\\\\slash"'))->tokens(),
        );
    }

    public function testAnUnterminatedListIsAnError(): void
    {
        $this->expectException(ProtocolError::class);

        (new ResponseLine('* X (a b'))->tokens();
    }

    public function testAnUnterminatedQuotedStringIsAnError(): void
    {
        $this->expectException(ProtocolError::class);

        (new ResponseLine('* X "unclosed'))->tokens();
    }

    // -----------------------------------------------------------------
    // Completion lines
    // -----------------------------------------------------------------

    public function testATaggedLineBecomesAResultWithItsResponseCodeSeparated(): void
    {
        $result = ResponseParser::completion(
            new ResponseLine('A0004 NO [ALREADYEXISTS] Mailbox already exists'),
            'A0004',
        );

        self::assertInstanceOf(CommandResult::class, $result);
        self::assertSame(ResponseStatus::No, $result->status);
        self::assertSame('ALREADYEXISTS', $result->responseCode);
        self::assertSame('Mailbox already exists', $result->text);
        self::assertTrue($result->hasResponseCode('alreadyexists'));
    }

    public function testAResponseCodeWithArgumentsKeepsOnlyItsFirstWord(): void
    {
        [$code, $text] = ResponseParser::splitResponseCode('[RIGHTS lrs] Completed');

        self::assertSame('RIGHTS', $code);
        self::assertSame('Completed', $text);
    }

    public function testALineWithoutAResponseCodeHasNone(): void
    {
        $result = ResponseParser::completion(new ResponseLine('A0001 OK Completed'), 'A0001');

        self::assertInstanceOf(CommandResult::class, $result);
        self::assertNull($result->responseCode);
        self::assertTrue($result->isOk());
    }

    public function testUntaggedAndContinuationLinesAreNotCompletions(): void
    {
        self::assertNull(ResponseParser::completion(new ResponseLine('* OK ready'), 'A0001'));
        self::assertNull(ResponseParser::completion(new ResponseLine('+ go ahead'), 'A0001'));
        self::assertNull(ResponseParser::completion(new ResponseLine('+'), 'A0001'));
    }

    /**
     * The reason unique tags exist: with the legacy client's constant `.` a
     * stale answer to the *previous* command was accepted as this one's result.
     */
    public function testAForeignTagIsRefusedRatherThanAccepted(): void
    {
        $this->expectException(ProtocolError::class);

        ResponseParser::completion(new ResponseLine('A0009 OK Completed'), 'A0004');
    }

    public function testAnUnparseableTaggedLineIsAnError(): void
    {
        $this->expectException(ProtocolError::class);

        ResponseParser::completion(new ResponseLine('A0001 MAYBE Completed'), 'A0001');
    }

    // -----------------------------------------------------------------
    // Words and grouping
    // -----------------------------------------------------------------

    public function testWordsFlattensNestingAway(): void
    {
        self::assertSame(
            ['*', 'CAPABILITY', 'IMAP4REV1', 'AUTH=PLAIN', 'QUOTA'],
            ResponseParser::words(new ResponseLine('* CAPABILITY IMAP4REV1 (AUTH=PLAIN QUOTA)')),
        );
    }

    public function testUntaggedLinesAreSelectableByKeyword(): void
    {
        $result = (new CommandResult(ResponseStatus::Ok, 'Completed', null, []))->withUntagged([
            new ResponseLine('* QUOTA "user/anna" (STORAGE 1 2)'),
            new ResponseLine('* LIST () "/" "user/anna"'),
            new ResponseLine('* QUOTA "user/bruno" (STORAGE 3 4)'),
        ]);

        self::assertCount(2, $result->linesWith('quota'));
        self::assertSame(
            'user/anna',
            $result->firstLineWith('QUOTA')?->tokens()[2] ?? null,
        );
        self::assertNull($result->firstLineWith('ACL'));
    }
}

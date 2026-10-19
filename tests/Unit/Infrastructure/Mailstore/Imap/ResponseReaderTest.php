<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\Imap\ResponseLine;
use Mailadmin\Infrastructure\Mailstore\Imap\ResponseReader;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseReader::class)]
#[CoversClass(ResponseLine::class)]
final class ResponseReaderTest extends TestCase
{
    /** `New` CRLF `Mail/Sent` — fourteen bytes, two of which end a line. */
    private const string AWKWARD_NAME = "New\r\nMail/Sent";

    public function testAPlainLineComesBackWithoutItsTerminator(): void
    {
        $line = $this->readerFor(ScriptedTransport::encrypted('* OK ready'))->read();

        self::assertSame('* OK ready', $line->text);
        self::assertSame([], $line->literals);
    }

    /**
     * The case the reader exists for: the announced bytes may contain anything,
     * including the CRLF that would otherwise end the line. Read line by line,
     * this one mailbox becomes two responses, and the second parses as
     * something else entirely.
     */
    public function testALiteralIsReadAsBytesAndNotAsText(): void
    {
        $transport = ScriptedTransport::encrypted('* LIST () "/" {14}')
            ->willSendBytes(self::AWKWARD_NAME)
            ->willSay('');

        $line = $this->readerFor($transport)->read();

        self::assertSame([self::AWKWARD_NAME], $line->literals);
        self::assertSame(self::AWKWARD_NAME, $line->tokens()[4] ?? null);
    }

    public function testSeveralLiteralsInOneLineAreKeptApart(): void
    {
        $transport = ScriptedTransport::encrypted('* ACL {5}')
            ->willSendBytes('inbox')
            ->willSay(' anna {4}')
            ->willSendBytes('lrsw')
            ->willSay('');

        $line = $this->readerFor($transport)->read();

        self::assertSame(['inbox', 'lrsw'], $line->literals);
        self::assertSame(['*', 'ACL', 'inbox', 'anna', 'lrsw'], $line->tokens());
    }

    /**
     * `{n}` is a number the *server* picks. Without a ceiling it is a request to
     * allocate arbitrary memory, and PHP answers that with a fatal error rather
     * than an exception anyone can catch.
     */
    public function testAnOversizedLiteralIsRefusedBeforeAnythingIsAllocated(): void
    {
        $reader = new ResponseReader(
            ScriptedTransport::encrypted('* SOMETHING {99999}'),
            ProtocolTrace::disabled(),
            1024,
        );

        try {
            $reader->read();
            self::fail('An oversized literal must not be read.');
        } catch (ProtocolError $error) {
            self::assertSame(ErrorCode::PROTOCOL_ERROR, $error->errorCode());
            self::assertStringContainsString('99999', $error->getMessage());
        }
    }

    public function testBracesInTheMiddleOfALineAreJustCharacters(): void
    {
        $line = $this->readerFor(
            ScriptedTransport::encrypted('* LIST () "/" "a{3}b"'),
        )->read();

        self::assertSame([], $line->literals);
        self::assertSame('a{3}b', $line->tokens()[4] ?? null);
    }

    /**
     * A trace must be able to show a response without showing what it carried:
     * a literal in an answer can be a Sieve script, and a Sieve script can hold
     * a vacation message.
     */
    public function testTheTraceShowsALiteralAsItsLengthAndNotItsContent(): void
    {
        $transport = ScriptedTransport::encrypted('* SCRIPT {17}')
            ->willSendBytes('vacation :days 7;')
            ->willSay('');

        $line = $this->readerFor($transport)->read();

        self::assertStringNotContainsString('vacation', $line->trace());
        self::assertStringContainsString('{17}', $line->trace());
    }

    private function readerFor(ScriptedTransport $transport): ResponseReader
    {
        return new ResponseReader($transport, ProtocolTrace::disabled());
    }
}

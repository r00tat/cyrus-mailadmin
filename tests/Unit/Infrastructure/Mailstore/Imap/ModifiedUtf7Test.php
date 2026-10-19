<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\Imap\ModifiedUtf7;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModifiedUtf7::class)]
final class ModifiedUtf7Test extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'plain ascii' => ['INBOX', 'INBOX'];
        yield 'a space' => ['Sent Items', 'Sent Items'];
        yield 'an ampersand' => ['R&D', 'R&-D'];
        yield 'only an ampersand' => ['&', '&-'];
        // The examples from RFC 3501, section 5.1.3.
        yield 'RFC example, money' => ['~peter/mail/台北/日本語', '~peter/mail/&U,BTFw-/&ZeVnLIqe-'];
        yield 'german umlaut' => ['Entwürfe', 'Entw&APw-rfe'];
        yield 'umlaut and ampersand' => ['Ü&Ö', '&ANw-&-&ANY-'];
        // Above the BMP: a surrogate pair, which is where a hand-written
        // encoder usually breaks.
        // U+1F4EE, which is the surrogate pair D83D DCEE on the wire.
        yield 'astral plane' => ['📮', '&2D3c7g-'];
    }

    #[DataProvider('names')]
    public function testEncoding(string $utf8, string $wire): void
    {
        self::assertSame($wire, ModifiedUtf7::encode($utf8));
    }

    #[DataProvider('names')]
    public function testDecodingIsTheInverse(string $utf8, string $wire): void
    {
        self::assertSame($utf8, ModifiedUtf7::decode($wire));
    }

    /**
     * Without the encoding the name goes out as raw UTF-8, Cyrus stores those
     * bytes, and the folder the user sees afterwards is called something else.
     */
    public function testAPlainNameIsRecognisedAsNeedingNothing(): void
    {
        self::assertTrue(ModifiedUtf7::isPlain('user/anna.example.test'));
        self::assertFalse(ModifiedUtf7::isPlain('Entwürfe'));
        self::assertFalse(ModifiedUtf7::isPlain('R&D'));
    }

    /**
     * Reading is where legacy data arrives, and a mailbox whose name confuses us
     * has to stay visible (U-22). Rejecting it would hide a mailbox that exists.
     */
    #[DataProvider('undecodableNames')]
    public function testAnUndecodableNameComesBackUnchangedRatherThanRejected(string $wire): void
    {
        self::assertSame($wire, ModifiedUtf7::decode($wire));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function undecodableNames(): iterable
    {
        yield 'unterminated run' => ['Entw&APw'];
        yield 'not base64' => ['&!!!-'];
        yield 'a lone high surrogate' => ['&2D0-'];
        yield 'an odd byte count' => ['&AA-'];
    }

    /**
     * Ill-formed input is rejected on the way *out*, because sending those bytes
     * creates a mailbox nobody can address again.
     */
    public function testEncodingRejectsInvalidUtf8(): void
    {
        $this->expectException(ProtocolError::class);

        ModifiedUtf7::encode("\xC3\x28");
    }

    public function testEncodingRejectsAnOverlongSequence(): void
    {
        $this->expectException(ProtocolError::class);

        // U+002F written in three bytes — the classic way to smuggle a `/` past
        // a check that ran on the decoded form.
        ModifiedUtf7::encode("\xE0\x80\xAF");
    }

    public function testEncodingRejectsAnEncodedSurrogate(): void
    {
        $this->expectException(ProtocolError::class);

        ModifiedUtf7::encode("\xED\xA0\x80");
    }

    public function testEncodingRejectsATruncatedSequence(): void
    {
        $this->expectException(ProtocolError::class);

        ModifiedUtf7::encode("Entw\xC3");
    }
}

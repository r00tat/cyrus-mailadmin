<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\Net\Transport;

/**
 * How a value gets onto the wire: atom, quoted string or literal (RFC 3501).
 *
 * The legacy client wrapped everything in quotation marks and hoped. That works
 * until a mailbox name contains a quotation mark, a backslash, a newline or a
 * byte above 0x7F — at which point the command is not merely wrong, it is
 * *reinterpreted*: the server sees the rest of the name as further arguments.
 * For `SETACL` and `DELETE` that is the difference between the intended mailbox
 * and another one.
 *
 * The three forms, and when each applies:
 *
 * | form     | when                                            |
 * |----------|-------------------------------------------------|
 * | atom     | never chosen automatically — see below          |
 * | quoted   | 7-bit, no CR/LF/NUL, and short                  |
 * | literal  | everything else, and anything long              |
 *
 * An atom is never chosen automatically even where it would be legal. It saves
 * two bytes and costs a rule that has to be right for every value; a quoted
 * string is always accepted where an astring is expected. Atoms are still sent —
 * for keywords such as `STORAGE` or `INBOX` — but only when the caller says so
 * with {@see ImapArgument::atom()}, where the value is a constant in our own
 * source and not user input.
 */
final class Literal
{
    /**
     * Beyond this a quotable value still travels as a literal.
     *
     * Not a protocol rule. A quoted string has to be escaped, counted and read
     * back as one line, and servers differ on how long a line they accept; a
     * literal has an explicit length and no escaping. The cost of preferring it
     * for anything sizeable is one extra round trip.
     */
    public const int QUOTED_MAX_LENGTH = 1024;

    private function __construct()
    {
    }

    /**
     * Can this value travel as a quoted string?
     *
     * `false` means literal — that is the whole decision. CR, LF and NUL end a
     * line or a C string; bytes above 0x7F are not allowed in an RFC 3501
     * quoted string, only in a literal.
     */
    public static function isQuotable(string $value): bool
    {
        if (strlen($value) > self::QUOTED_MAX_LENGTH) {
            return false;
        }

        return preg_match('/[^\x01-\x7F]|[\r\n]/', $value) !== 1;
    }

    /**
     * The quoted form, with `"` and `\` escaped and nothing else touched.
     *
     * The caller has to have asked {@see isQuotable()} first; quoting a value
     * that needs a literal would produce a syntactically valid command with the
     * wrong content, which is precisely the failure this class exists to stop.
     */
    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * The announcement that precedes the bytes: `{n}\r\n`.
     *
     * The length is in **bytes**, not characters. A mailbox name in modified
     * UTF-7 is ASCII, but a Sieve script (M3) is UTF-8, and counting its
     * characters would announce a length the server never receives.
     */
    public static function header(int $byteLength): string
    {
        return '{' . $byteLength . '}' . Transport::CRLF;
    }

    /**
     * Is this a legal atom? Used to check the constants we send ourselves, so
     * that a typo in a keyword fails here rather than as a `BAD` from the
     * server.
     *
     * atom-specials are `( ) { SP CTL % * " \ ]`; `]` is allowed in an astring
     * and therefore tolerated here.
     */
    public static function isAtom(string $value): bool
    {
        return preg_match('/^[^\x00-\x20\x7F(){%*"\\\\]+$/', $value) === 1;
    }

    /**
     * The `{n}` announcement at the end of a response line, or `null`.
     *
     * `{42}` at the very end means the server is about to send 42 raw bytes.
     * Anywhere else in the line the braces are just characters.
     */
    public static function announcedLength(string $line): ?int
    {
        if (preg_match('/\{(\d+)\}$/', $line, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\ProtocolError;

/**
 * Mailbox names on the wire: modified UTF-7 (RFC 3501, section 5.1.3).
 *
 * A mailbox name in IMAP is not UTF-8. Printable US-ASCII travels as itself,
 * `&` becomes `&-`, and everything else is modified BASE64 of the UTF-16BE form,
 * wrapped in `&` … `-`, with `,` in place of `/` and no padding.
 *
 * M1's open point 3 asked whether to support this or to restrict folder names to
 * ASCII. Supporting it is a few dozen lines and removes a class of bug that only
 * shows up for the people who name a folder `Entwürfe`: without the encoding the
 * name goes out as raw UTF-8, Cyrus stores those bytes, and the folder the user
 * sees afterwards is called something else. Restricting to ASCII would have to
 * be enforced somewhere, and that somewhere would be a validation rule the
 * legacy data already violates.
 *
 * **The conversion is written out by hand** rather than delegated to `mbstring`
 * or `iconv`. Neither is declared in `composer.json`, and declaring one to
 * transcode two hundred bytes would be a new requirement on the target host for
 * no gain (E-12).
 */
final class ModifiedUtf7
{
    /** BASE64 with `,` for `/`, as the RFC prescribes. */
    private const string ALPHABET =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+,';

    private function __construct()
    {
    }

    /**
     * UTF-8 in, modified UTF-7 out.
     *
     * @throws ProtocolError when the input is not valid UTF-8 — sending
     *                       ill-formed bytes as a mailbox name creates a mailbox
     *                       nobody can address again
     */
    public static function encode(string $utf8): string
    {
        $codePoints = self::codePoints($utf8);
        $encoded = '';
        /** @var list<int> $pending code units waiting for a BASE64 run */
        $pending = [];

        foreach ($codePoints as $codePoint) {
            if (self::isDirect($codePoint)) {
                $encoded .= self::flush($pending);
                $pending = [];
                $encoded .= $codePoint === 0x26 ? '&-' : chr($codePoint);

                continue;
            }

            foreach (self::toUtf16Units($codePoint) as $unit) {
                $pending[] = $unit;
            }
        }

        return $encoded . self::flush($pending);
    }

    /**
     * Modified UTF-7 in, UTF-8 out.
     *
     * Tolerant on purpose: a name that does not decode is returned unchanged
     * rather than rejected. Reading is where legacy data arrives, and a mailbox
     * whose name confuses us has to stay *visible* (U-22) — hiding it is how the
     * 33 shadow mailboxes in the legacy system stayed invisible for years.
     */
    public static function decode(string $encoded): string
    {
        $out = '';
        $length = strlen($encoded);
        $index = 0;

        while ($index < $length) {
            $character = $encoded[$index];

            if ($character !== '&') {
                $out .= $character;
                ++$index;

                continue;
            }

            $end = strpos($encoded, '-', $index + 1);

            if ($end === false) {
                // An unterminated run. Keep the rest verbatim.
                return $out . substr($encoded, $index);
            }

            $run = substr($encoded, $index + 1, $end - $index - 1);
            $index = $end + 1;

            if ($run === '') {
                $out .= '&';

                continue;
            }

            $decoded = self::decodeRun($run);

            $out .= $decoded ?? '&' . $run . '-';
        }

        return $out;
    }

    /**
     * Does the name need encoding at all? Answering no keeps the common case
     * byte-identical, which makes a protocol trace readable.
     */
    public static function isPlain(string $name): bool
    {
        return preg_match('/^[\x20-\x25\x27-\x7E]*$/', $name) === 1;
    }

    /**
     * Everything printable US-ASCII except `&` travels as itself. The RFC allows
     * `&` to be sent directly too, but only as `&-`; treating it as indirect
     * here keeps that in one place.
     */
    private static function isDirect(int $codePoint): bool
    {
        return $codePoint >= 0x20 && $codePoint <= 0x7E;
    }

    /**
     * @param list<int> $units UTF-16 code units
     */
    private static function flush(array $units): string
    {
        if ($units === []) {
            return '';
        }

        $bytes = '';

        foreach ($units as $unit) {
            $bytes .= chr($unit >> 8) . chr($unit & 0xFF);
        }

        return '&' . self::toModifiedBase64($bytes) . '-';
    }

    private static function toModifiedBase64(string $bytes): string
    {
        $standard = rtrim(base64_encode($bytes), '=');

        return strtr($standard, '/', ',');
    }

    /**
     * @return list<int> UTF-16 code units, surrogate pair for anything above the BMP
     */
    private static function toUtf16Units(int $codePoint): array
    {
        if ($codePoint <= 0xFFFF) {
            return [$codePoint];
        }

        $offset = $codePoint - 0x10000;

        return [0xD800 + ($offset >> 10), 0xDC00 + ($offset & 0x3FF)];
    }

    /**
     * A BASE64 run back to UTF-8, or `null` when it does not decode.
     */
    private static function decodeRun(string $run): ?string
    {
        if ($run === '' || strspn($run, self::ALPHABET) !== strlen($run)) {
            return null;
        }

        $standard = strtr($run, ',', '/');
        $padding = strlen($standard) % 4;

        if ($padding === 1) {
            return null;
        }

        if ($padding !== 0) {
            $standard .= str_repeat('=', 4 - $padding);
        }

        $bytes = base64_decode($standard, true);

        if ($bytes === false || strlen($bytes) % 2 !== 0) {
            return null;
        }

        $units = [];

        for ($offset = 0; $offset < strlen($bytes); $offset += 2) {
            $units[] = (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
        }

        return self::unitsToUtf8($units);
    }

    /**
     * @param list<int> $units
     */
    private static function unitsToUtf8(array $units): ?string
    {
        $out = '';
        $count = count($units);

        for ($index = 0; $index < $count; ++$index) {
            $unit = $units[$index];

            if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                $low = $units[$index + 1] ?? null;

                if ($low === null || $low < 0xDC00 || $low > 0xDFFF) {
                    return null;
                }

                ++$index;
                $out .= self::utf8Character(
                    0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00),
                );

                continue;
            }

            if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                return null;
            }

            $out .= self::utf8Character($unit);
        }

        return $out;
    }

    private static function utf8Character(int $codePoint): string
    {
        if ($codePoint <= 0x7F) {
            return chr($codePoint);
        }

        if ($codePoint <= 0x7FF) {
            return chr(0xC0 | ($codePoint >> 6))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint <= 0xFFFF) {
            return chr(0xE0 | ($codePoint >> 12))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        return chr(0xF0 | ($codePoint >> 18))
            . chr(0x80 | (($codePoint >> 12) & 0x3F))
            . chr(0x80 | (($codePoint >> 6) & 0x3F))
            . chr(0x80 | ($codePoint & 0x3F));
    }

    /**
     * UTF-8 to code points, strictly. Overlong forms, surrogates and truncated
     * sequences are rejected — every one of them is a way to smuggle a different
     * name past a validation that ran on the decoded form.
     *
     * @return list<int>
     *
     * @throws ProtocolError
     */
    private static function codePoints(string $utf8): array
    {
        $points = [];
        $length = strlen($utf8);
        $index = 0;

        while ($index < $length) {
            $byte = ord($utf8[$index]);

            if ($byte <= 0x7F) {
                $points[] = $byte;
                ++$index;

                continue;
            }

            [$codePoint, $width, $minimum] = match (true) {
                $byte >= 0xC2 && $byte <= 0xDF => [$byte & 0x1F, 2, 0x80],
                $byte >= 0xE0 && $byte <= 0xEF => [$byte & 0x0F, 3, 0x800],
                $byte >= 0xF0 && $byte <= 0xF4 => [$byte & 0x07, 4, 0x10000],
                default => throw ProtocolError::unusableArgument(
                    $utf8,
                    'it is not valid UTF-8',
                ),
            };

            if ($index + $width > $length) {
                throw ProtocolError::unusableArgument($utf8, 'it ends inside a UTF-8 sequence');
            }

            for ($continuation = 1; $continuation < $width; ++$continuation) {
                $next = ord($utf8[$index + $continuation]);

                if (($next & 0xC0) !== 0x80) {
                    throw ProtocolError::unusableArgument($utf8, 'it is not valid UTF-8');
                }

                $codePoint = ($codePoint << 6) | ($next & 0x3F);
            }

            if ($codePoint < $minimum || $codePoint > 0x10FFFF) {
                throw ProtocolError::unusableArgument($utf8, 'it holds an overlong UTF-8 sequence');
            }

            if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
                throw ProtocolError::unusableArgument($utf8, 'it holds an encoded surrogate');
            }

            $points[] = $codePoint;
            $index += $width;
        }

        return $points;
    }
}

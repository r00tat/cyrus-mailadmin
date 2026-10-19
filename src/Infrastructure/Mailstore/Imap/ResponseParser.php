<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\ProtocolError;

/**
 * Turns a response line into tokens, and a tagged line into a result.
 *
 * Deliberately small. This is not a general IMAP parser — the application never
 * fetches a message, so there is no envelope, no body structure and no sequence
 * set to understand. What it has to cope with is the handful of shapes the admin
 * commands produce:
 *
 * ```text
 * * LIST (\HasNoChildren) "/" "user/anna.example.test"
 * * QUOTA "user/anna.example.test" (STORAGE 1234 51200)
 * * ACL user/anna.example.test anna.example.test lrswipkxtecda cyrus lrswipkxtecda
 * A0003 OK [READ-WRITE] Completed
 * A0004 NO [ALREADYEXISTS] Mailbox already exists
 * ```
 *
 * Two shapes are worth naming because getting them wrong is expensive:
 *
 *  - **Flags contain a backslash** (`\HasNoChildren`). A tokenizer that treats
 *    `\` as an escape outside a quoted string loses them.
 *  - **The response code in brackets is not part of the text.** `[ALREADYEXISTS]`
 *    is a machine-readable statement (RFC 5530); the English after it is not.
 *    M2 branches on the code, never on the sentence.
 */
final class ResponseParser
{
    private function __construct()
    {
    }

    /**
     * @return list<mixed> string for atoms, quoted strings and literals; `null`
     *                     for `NIL`; nested `list<mixed>` for `(…)` and `[…]`
     */
    public static function tokenize(ResponseLine $line): array
    {
        $position = 0;

        return self::readList($line, $position, null);
    }

    /**
     * The status of a tagged completion line, or `null` when the line is not
     * tagged with `$tag`.
     *
     * @throws ProtocolError when the line is tagged with something else — a
     *                       mismatched tag means the stream is out of step and
     *                       everything after it would be a guess
     */
    public static function completion(ResponseLine $line, string $tag): ?CommandResult
    {
        if ($line->isUntagged() || $line->isContinuation()) {
            return null;
        }

        if (preg_match('/^(\S+)\s+(OK|NO|BAD)\b\s*(.*)$/is', $line->text, $matches) !== 1) {
            throw ProtocolError::malformedLine($line->text);
        }

        if ($matches[1] !== $tag) {
            throw ProtocolError::unexpectedTag($tag, $matches[1]);
        }

        $status = ResponseStatus::from(strtoupper($matches[2]));
        [$code, $text] = self::splitResponseCode($matches[3]);

        return new CommandResult($status, $text, $code, []);
    }

    /**
     * Splits `[ALREADYEXISTS] Mailbox already exists` into the code and the
     * text. A code with arguments (`[TRYCREATE]`, `[RIGHTS lrs]`) keeps only its
     * first word — that is the part with a defined meaning.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function splitResponseCode(string $text): array
    {
        $trimmed = ltrim($text);

        if (!str_starts_with($trimmed, '[')) {
            return [null, trim($trimmed)];
        }

        $end = strpos($trimmed, ']');

        if ($end === false) {
            return [null, trim($trimmed)];
        }

        $inside = substr($trimmed, 1, $end - 1);
        $space = strpos($inside, ' ');
        $code = $space === false ? $inside : substr($inside, 0, $space);

        return [
            strtoupper(trim($code)),
            trim(substr($trimmed, $end + 1)),
        ];
    }

    /**
     * Every token of a line as a flat list of strings, lists flattened away.
     *
     * Handy for `CAPABILITY` and for the SASL mechanism list, where the shape
     * carries no information and only the words matter.
     *
     * @return list<string>
     */
    public static function words(ResponseLine $line): array
    {
        return self::flatten(self::tokenize($line));
    }

    /**
     * Takes any array rather than a `list` so that a nested token — which the
     * analyser only knows as `array` after `is_array()` — needs no cast.
     *
     * @param array<mixed> $tokens
     *
     * @return list<string>
     */
    private static function flatten(array $tokens): array
    {
        $words = [];

        foreach ($tokens as $token) {
            if (is_array($token)) {
                foreach (self::flatten($token) as $word) {
                    $words[] = $word;
                }

                continue;
            }

            if (is_string($token)) {
                $words[] = $token;
            }
        }

        return $words;
    }

    /**
     * @return list<mixed>
     *
     * @throws ProtocolError
     */
    private static function readList(ResponseLine $line, int &$position, ?string $closer): array
    {
        $tokens = [];
        $text = $line->text;
        $length = strlen($text);

        while ($position < $length) {
            $character = $text[$position];

            if ($character === ' ') {
                ++$position;

                continue;
            }

            if ($closer !== null && $character === $closer) {
                ++$position;

                return $tokens;
            }

            $tokens[] = match ($character) {
                '(' => self::openList($line, $position, ')'),
                '[' => self::openList($line, $position, ']'),
                '"' => self::readQuoted($line, $position),
                default => self::readBare($line, $position, $closer),
            };
        }

        if ($closer !== null) {
            throw ProtocolError::malformedLine($text);
        }

        return $tokens;
    }

    /**
     * @return list<mixed>
     *
     * @throws ProtocolError
     */
    private static function openList(ResponseLine $line, int &$position, string $closer): array
    {
        ++$position;

        return self::readList($line, $position, $closer);
    }

    /**
     * @throws ProtocolError
     */
    private static function readQuoted(ResponseLine $line, int &$position): string
    {
        $text = $line->text;
        $length = strlen($text);
        $value = '';
        ++$position;

        while ($position < $length) {
            $character = $text[$position];

            if ($character === '\\' && $position + 1 < $length) {
                $value .= $text[$position + 1];
                $position += 2;

                continue;
            }

            if ($character === '"') {
                ++$position;

                return $value;
            }

            $value .= $character;
            ++$position;
        }

        throw ProtocolError::malformedLine($text);
    }

    /**
     * An atom, a literal placeholder or `NIL`.
     *
     * Backslashes are part of the atom here, not escapes: `\Noselect` is one
     * token and losing its backslash would turn a flag into a folder name.
     */
    private static function readBare(ResponseLine $line, int &$position, ?string $closer): string|null
    {
        $text = $line->text;
        $length = strlen($text);
        $start = $position;

        while ($position < $length) {
            $character = $text[$position];

            if ($character === ' ' || $character === '(' || $character === ')') {
                break;
            }

            if ($closer !== null && $character === $closer) {
                break;
            }

            ++$position;
        }

        $token = substr($text, $start, $position - $start);

        if (preg_match('/^\x00L(\d+)\x00$/', $token, $matches) === 1) {
            return $line->literals[(int) $matches[1]] ?? '';
        }

        return strcasecmp($token, 'NIL') === 0 ? null : $token;
    }
}

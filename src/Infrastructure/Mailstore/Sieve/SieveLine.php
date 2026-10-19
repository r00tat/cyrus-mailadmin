<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Sieve;

/**
 * One *logical* line of a ManageSieve response, tokenised (RFC 5804, section 4).
 *
 * A logical line is not a physical one. `"SIEVE" {42}` announces forty-two raw
 * octets that follow the CRLF, and those octets may contain CRLF, a quotation
 * mark or a brace — a Sieve script certainly does. {@see ManageSieveConnection}
 * reads them, parks them here and puts a placeholder where the announcement
 * stood, which is why the tokeniser below never has to know that a literal was
 * involved.
 *
 * **This is a tokeniser, not a Sieve parser.** It splits a *protocol* line into
 * strings, atoms and the parenthesised response code; it never looks inside a
 * script. Architecture 9.4 is explicit that scripts are generated and not read
 * back, and the one thing that *is* read back — the mailadmin marker — is
 * {@see \Mailadmin\Domain\Sieve\SieveScript::isManaged()}'s business, not this
 * class's.
 *
 * Three token kinds, because ManageSieve needs exactly three:
 *
 * | kind     | looks like                | example                        |
 * |----------|---------------------------|--------------------------------|
 * | `string` | quoted string or literal  | `"sieve"`, `{42}` + octets     |
 * | `atom`   | a bare word               | `OK`, `ACTIVE`                 |
 * | `code`   | parenthesised group       | `(NONEXISTENT)`, `(QUOTA/MAXSIZE)` |
 *
 * Keeping strings and atoms apart is not pedantry: `LISTSCRIPTS` answers
 * `"name" ACTIVE`, and a script *called* `ACTIVE` arrives quoted. An
 * implementation that flattened both into "words" would mark the wrong script as
 * the active one.
 */
final readonly class SieveLine
{
    public const string KIND_STRING = 'string';
    public const string KIND_ATOM = 'atom';
    public const string KIND_CODE = 'code';

    /** The completion words. Everything else on a line is data. */
    private const array STATUS_WORDS = ['OK', 'NO', 'BYE'];

    /**
     * Wraps the index of a parked literal. NUL cannot occur in a ManageSieve
     * line — the grammar excludes it from every production — so it cannot be
     * confused with content the server sent.
     */
    private const string MARK = "\x00";

    /**
     * @param list<string> $literals the octets of each parked literal, in the
     *                               order their announcements appeared
     */
    private function __construct(
        public string $text,
        private array $literals,
    ) {
    }

    /**
     * @param list<string> $literals
     */
    public static function of(string $text, array $literals = []): self
    {
        return new self($text, $literals);
    }

    public static function placeholder(int $index): string
    {
        return self::MARK . $index . self::MARK;
    }

    /**
     * `OK`, `NO`, `BYE` — or `null` when this line carries data rather than a
     * completion.
     *
     * ManageSieve has **no command tags**. There is nothing to match an answer
     * against, and RFC 5804 does not offer one: a response ends at the first
     * completion word and one command is in flight at a time. That is why
     * {@see ManageSieveConnection} writes one command and reads to its
     * completion before writing the next, and why pipelining is not done here.
     */
    public function statusWord(): ?string
    {
        $first = $this->tokens()[0] ?? null;

        if ($first === null || $first['kind'] !== self::KIND_ATOM) {
            return null;
        }

        $word = strtoupper($first['value']);

        return in_array($word, self::STATUS_WORDS, true) ? $word : null;
    }

    /**
     * The response code, upper case and without its parentheses: `NONEXISTENT`,
     * `ACTIVE`, `QUOTA/MAXSCRIPTS`.
     *
     * Only the first word of the group. `(REFERRAL "sieve://elsewhere")` is
     * `REFERRAL`; the argument is diagnostic and nothing here acts on it.
     */
    public function responseCode(): ?string
    {
        foreach ($this->tokens() as $token) {
            if ($token['kind'] !== self::KIND_CODE) {
                continue;
            }

            $words = preg_split('/\s+/', trim($token['value']), 2);
            $code = $words === false ? '' : trim($words[0] ?? '', '"');

            return $code === '' ? null : strtoupper($code);
        }

        return null;
    }

    /**
     * Every string on the line, in order.
     *
     * @return list<string>
     */
    public function strings(): array
    {
        $strings = [];

        foreach ($this->tokens() as $token) {
            if ($token['kind'] === self::KIND_STRING) {
                $strings[] = $token['value'];
            }
        }

        return $strings;
    }

    /**
     * Is `$name` one of the bare words on this line? Case-insensitive, as
     * RFC 5804 makes every atom.
     */
    public function hasAtom(string $name): bool
    {
        foreach ($this->tokens() as $token) {
            if ($token['kind'] === self::KIND_ATOM && strcasecmp($token['value'], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The human-readable text of a completion line, or an empty string.
     *
     * Server prose, written for an operator's log. It never reaches a client
     * (`CLAUDE.md`, "Fehler"), and the caller flattens it before it becomes part
     * of a message.
     */
    public function completionText(): string
    {
        return implode(' ', $this->strings());
    }

    /**
     * The line as it may appear in a transcript: literals as `{n}`, never their
     * octets. A literal in a ManageSieve answer *is* a Sieve script, and a Sieve
     * script holds a vacation message.
     */
    public function trace(): string
    {
        $rendered = $this->text;

        foreach ($this->literals as $index => $bytes) {
            $rendered = str_replace(
                self::placeholder($index),
                '{' . strlen($bytes) . '}',
                $rendered,
            );
        }

        return preg_replace('/[\x00-\x1F\x7F]/', '.', $rendered) ?? $rendered;
    }

    /**
     * @return list<array{kind: string, value: string}>
     */
    public function tokens(): array
    {
        $tokens = [];
        $length = strlen($this->text);
        $index = 0;

        while ($index < $length) {
            $char = $this->text[$index];

            if ($char === ' ' || $char === "\t") {
                ++$index;

                continue;
            }

            $tokens[] = match ($char) {
                '"' => ['kind' => self::KIND_STRING, 'value' => $this->readQuoted($index)],
                self::MARK => ['kind' => self::KIND_STRING, 'value' => $this->readLiteral($index)],
                '(' => ['kind' => self::KIND_CODE, 'value' => $this->readGroup($index)],
                default => ['kind' => self::KIND_ATOM, 'value' => $this->readAtom($index)],
            };
        }

        return $tokens;
    }

    /**
     * A quoted string. RFC 5804 escapes exactly two characters — `"` and `\` —
     * and leaves every other octet, UTF-8 included, alone.
     */
    private function readQuoted(int &$index): string
    {
        $length = strlen($this->text);
        $value = '';
        ++$index;

        while ($index < $length) {
            $char = $this->text[$index];

            if ($char === '\\' && $index + 1 < $length) {
                $value .= $this->text[$index + 1];
                $index += 2;

                continue;
            }

            if ($char === '"') {
                ++$index;

                return $value;
            }

            $value .= $char;
            ++$index;
        }

        // Unterminated. Handing back what there was rather than throwing: the
        // caller decides, and it can say more about which command it was.
        return $value;
    }

    private function readLiteral(int &$index): string
    {
        $end = strpos($this->text, self::MARK, $index + 1);

        if ($end === false) {
            ++$index;

            return '';
        }

        $reference = substr($this->text, $index + 1, $end - $index - 1);
        $index = $end + 1;

        return $this->literals[(int) $reference] ?? '';
    }

    /**
     * The inside of a `( … )` group, quotation marks respected so that a
     * `REFERRAL "sieve://host)"` does not end the group early.
     */
    private function readGroup(int &$index): string
    {
        $length = strlen($this->text);
        $value = '';
        $depth = 0;
        $quoted = false;

        while ($index < $length) {
            $char = $this->text[$index];
            ++$index;

            if ($quoted) {
                if ($char === '\\' && $index < $length) {
                    $value .= $char . $this->text[$index];
                    ++$index;

                    continue;
                }

                $quoted = $char !== '"';
                $value .= $char;

                continue;
            }

            if ($char === '"') {
                $quoted = true;
                $value .= $char;

                continue;
            }

            if ($char === '(') {
                ++$depth;

                if ($depth === 1) {
                    continue;
                }
            }

            if ($char === ')') {
                --$depth;

                if ($depth === 0) {
                    return $value;
                }
            }

            $value .= $char;
        }

        return $value;
    }

    private function readAtom(int &$index): string
    {
        $length = strlen($this->text);
        $value = '';

        while ($index < $length) {
            $char = $this->text[$index];

            if ($char === ' ' || $char === "\t") {
                break;
            }

            $value .= $char;
            ++$index;
        }

        return $value;
    }
}

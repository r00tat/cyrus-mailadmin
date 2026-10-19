<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

/**
 * One logical response line, with its literals kept separate.
 *
 * A response line is not a line. `* LIST () "/" {13}` is followed by thirteen
 * raw bytes and then the rest of the line, and those bytes may contain a
 * quotation mark, a brace or a CRLF. Splicing them back into the text would make
 * the result unparseable in exactly the cases literals exist for.
 *
 * So the reader replaces each literal with a placeholder that cannot occur in
 * IMAP text — NUL is forbidden outside a literal — and keeps the bytes in
 * {@see $literals}. The tokenizer resolves the placeholder back to the content.
 */
final readonly class ResponseLine
{
    /** `%d` is the index into {@see $literals}. NUL cannot appear in IMAP text. */
    public const string PLACEHOLDER_FORMAT = "\x00L%d\x00";

    /**
     * @param list<string> $literals in the order they appeared
     */
    public function __construct(
        public string $text,
        public array $literals = [],
    ) {
    }

    public static function placeholder(int $index): string
    {
        return sprintf(self::PLACEHOLDER_FORMAT, $index);
    }

    /**
     * Is this an untagged line (`* …`)?
     */
    public function isUntagged(): bool
    {
        return str_starts_with($this->text, '* ');
    }

    /**
     * Is this a continuation request (`+ …`)?
     */
    public function isContinuation(): bool
    {
        return $this->text === '+' || str_starts_with($this->text, '+ ');
    }

    /**
     * The untagged line without its `* `, or the line itself when it has none.
     */
    public function payload(): string
    {
        return $this->isUntagged() ? substr($this->text, 2) : $this->text;
    }

    /**
     * The first word of the payload, upper-cased: `LIST`, `QUOTA`, `ACL`, `OK`.
     */
    public function keyword(): string
    {
        $payload = $this->payload();
        $space = strpos($payload, ' ');

        return strtoupper($space === false ? $payload : substr($payload, 0, $space));
    }

    public function hasKeyword(string $keyword): bool
    {
        return $this->keyword() === strtoupper($keyword);
    }

    /**
     * @return list<mixed> tokens: string, null for NIL, nested list for `(…)`
     */
    public function tokens(): array
    {
        return ResponseParser::tokenize($this);
    }

    /**
     * A rendering for a log: literals shown as `{n}`, control characters
     * flattened. Never the payload — a literal can be a message body.
     */
    public function trace(): string
    {
        $rendered = $this->text;

        foreach (array_keys($this->literals) as $index) {
            $rendered = str_replace(
                self::placeholder($index),
                '{' . strlen($this->literals[$index]) . '}',
                $rendered,
            );
        }

        return preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/', '.', $rendered) ?? $rendered;
    }
}

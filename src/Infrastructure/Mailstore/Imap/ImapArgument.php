<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\ProtocolError;

/**
 * One argument of an IMAP command, and how it is allowed to travel.
 *
 * The kind is chosen at the call site, not guessed from the value, because the
 * two mistakes it prevents are different in nature:
 *
 *  - an **atom** that should have been a string turns a value into syntax;
 *  - a **secret** that should have been redacted turns a password into a log
 *    line.
 *
 * {@see secret()} is the reason this class exists at all rather than a plain
 * `string[]`. It marks a value the {@see ProtocolTrace} must never render, and
 * that mark travels with the value instead of relying on everyone remembering
 * which position of which command holds the password.
 */
final readonly class ImapArgument
{
    /**
     * @param list<ImapArgument> $items only for {@see ArgumentKind::ParenList}
     */
    private function __construct(
        public ArgumentKind $kind,
        public string $value,
        public array $items = [],
    ) {
    }

    /**
     * A keyword: `STORAGE`, `INBOX`, `PLAIN`. Verbatim, unquoted.
     *
     * @throws ProtocolError when the value is not a legal atom — that means a
     *                       constant in our own source is wrong
     */
    public static function atom(string $value): self
    {
        if (!Literal::isAtom($value)) {
            throw ProtocolError::unusableArgument($value, 'it is not a legal atom');
        }

        return new self(ArgumentKind::Atom, $value);
    }

    public static function number(int $value): self
    {
        return new self(ArgumentKind::Atom, (string) $value);
    }

    /**
     * Text whose form is decided by its content: quoted when it fits, literal
     * otherwise.
     */
    public static function text(string $value): self
    {
        return new self(ArgumentKind::Text, $value);
    }

    /**
     * A mailbox name given in UTF-8, encoded to modified UTF-7 on the way out.
     *
     * @throws ProtocolError when the name is not valid UTF-8
     */
    public static function mailbox(string $utf8Name): self
    {
        return new self(ArgumentKind::Text, ModifiedUtf7::encode($utf8Name));
    }

    /**
     * Bytes that must travel as a literal whatever they look like — a Sieve
     * script, a message body.
     */
    public static function literal(string $value): self
    {
        return new self(ArgumentKind::Literal, $value);
    }

    /**
     * A value that is never written to a log or a trace.
     *
     * Sent exactly like {@see text()}. The difference is what
     * {@see ProtocolTrace} does with it.
     */
    public static function secret(string $value): self
    {
        return new self(ArgumentKind::Secret, $value);
    }

    public static function list(self ...$items): self
    {
        return new self(ArgumentKind::ParenList, '', array_values($items));
    }

    /**
     * Does this argument, or anything inside it, hold a secret?
     */
    public function isSensitive(): bool
    {
        if ($this->kind === ArgumentKind::Secret) {
            return true;
        }

        foreach ($this->items as $item) {
            if ($item->isSensitive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this argument have to be sent as a literal, with the round trip that
     * implies?
     */
    public function needsLiteral(): bool
    {
        return match ($this->kind) {
            ArgumentKind::Literal => true,
            ArgumentKind::Text, ArgumentKind::Secret => !Literal::isQuotable($this->value),
            ArgumentKind::Atom => false,
            ArgumentKind::ParenList => $this->listNeedsLiteral(),
        };
    }

    private function listNeedsLiteral(): bool
    {
        foreach ($this->items as $item) {
            if ($item->needsLiteral()) {
                return true;
            }
        }

        return false;
    }
}

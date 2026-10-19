<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\Net\Transport;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;

/**
 * One IMAP command, ready to be written.
 *
 * A command is not a string, because a literal splits it: the client writes up
 * to and including `{n}\r\n`, waits for the server's `+` continuation, then
 * writes the bytes and whatever follows them. {@see parts()} returns exactly
 * that sequence — every element but the last is followed by a wait.
 *
 * {@see trace()} renders the same command for a log, and it is the only
 * rendering that is ever allowed near one. See {@see ProtocolTrace} for the two
 * rules it enforces.
 */
final readonly class ImapCommand
{
    /**
     * Commands whose arguments are redacted whatever kind they were built with.
     *
     * {@see ImapArgument::secret()} is the intended way to mark a password. This
     * list is the second lock: if someone builds `LOGIN` with a plain
     * {@see ImapArgument::text()} argument, the trace still does not show it.
     * A test asserts both paths.
     */
    private const array SENSITIVE_COMMANDS = ['LOGIN', 'AUTHENTICATE'];

    /**
     * @param list<ImapArgument> $arguments
     */
    private function __construct(
        public string $name,
        public array $arguments,
    ) {
    }

    /**
     * @throws ProtocolError when the command name is not a legal atom
     */
    public static function of(string $name, ImapArgument ...$arguments): self
    {
        $upper = strtoupper(trim($name));

        if (!Literal::isAtom($upper)) {
            throw ProtocolError::unusableArgument($name, 'it is not a legal command name');
        }

        return new self($upper, array_values($arguments));
    }

    /**
     * The write sequence. Every part except the last one is followed by waiting
     * for a `+` continuation from the server.
     *
     * @return non-empty-list<string>
     */
    public function parts(string $tag): array
    {
        $parts = [];
        $buffer = $tag . ' ' . $this->name;

        foreach ($this->arguments as $argument) {
            $buffer = self::append($argument, $buffer, $parts, ' ');
        }

        $parts[] = $buffer . Transport::CRLF;

        return $parts;
    }

    /**
     * How many continuation round trips this command costs.
     */
    public function literalCount(string $tag): int
    {
        return count($this->parts($tag)) - 1;
    }

    /**
     * The command as a single line for a log — never the bytes that go out.
     *
     * Secrets become `<redacted>`, literal payloads become `{n}` with no
     * content. A literal payload could be a Sieve script holding a vacation
     * message, and a vacation message is personal data; its length is enough to
     * debug a framing bug.
     */
    public function trace(string $tag): string
    {
        $line = $tag . ' ' . $this->name;
        $redactAll = in_array($this->name, self::SENSITIVE_COMMANDS, true);

        foreach ($this->arguments as $index => $argument) {
            // AUTHENTICATE's first argument is the mechanism name, which is
            // useful and public; everything after it is credential material.
            $keep = $redactAll && $this->name === 'AUTHENTICATE' && $index === 0;

            $line .= ' ' . ($redactAll && !$keep
                ? ProtocolTrace::REDACTED
                : self::traceArgument($argument));
        }

        return $line;
    }

    public function isSensitive(): bool
    {
        if (in_array($this->name, self::SENSITIVE_COMMANDS, true)) {
            return true;
        }

        foreach ($this->arguments as $argument) {
            if ($argument->isSensitive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $parts
     */
    private static function append(
        ImapArgument $argument,
        string $buffer,
        array &$parts,
        string $separator,
    ): string {
        if ($argument->kind === ArgumentKind::ParenList) {
            $buffer .= $separator . '(';
            $inner = '';

            foreach ($argument->items as $item) {
                $buffer = self::append($item, $buffer, $parts, $inner);
                $inner = ' ';
            }

            return $buffer . ')';
        }

        if ($argument->needsLiteral()) {
            $parts[] = $buffer . $separator . Literal::header(strlen($argument->value));

            return $argument->value;
        }

        return $buffer . $separator . match ($argument->kind) {
            ArgumentKind::Atom => $argument->value,
            default => Literal::quote($argument->value),
        };
    }

    private static function traceArgument(ImapArgument $argument): string
    {
        return match ($argument->kind) {
            ArgumentKind::Secret => ProtocolTrace::REDACTED,
            ArgumentKind::Atom => $argument->value,
            ArgumentKind::Literal => '{' . strlen($argument->value) . '}',
            ArgumentKind::ParenList => '(' . implode(' ', array_map(
                static fn (ImapArgument $item): string => self::traceArgument($item),
                $argument->items,
            )) . ')',
            ArgumentKind::Text => $argument->needsLiteral()
                ? '{' . strlen($argument->value) . '}'
                : Literal::quote($argument->value),
        };
    }
}

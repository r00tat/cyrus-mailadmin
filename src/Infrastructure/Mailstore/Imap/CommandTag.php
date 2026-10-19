<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

/**
 * The tag that makes an answer attributable (architecture 9.2).
 *
 * The legacy client sent a constant `.` for every command. Any tagged line then
 * matched any command, so a leftover answer from the previous exchange was
 * accepted as the result of the current one — with `SETQUOTA` and `SETACL` on
 * the other end, that is a silent wrong outcome rather than a visible error.
 *
 * Here every command on a connection gets its own tag, and a mismatch is a
 * {@see ProtocolError}. The counter is per connection because that is the scope
 * in which uniqueness is required; a prefix drawn at random keeps two
 * connections in the same request distinguishable in a trace.
 */
final class CommandTag
{
    /**
     * Two letters of prefix, four digits of counter: `KQ0001`. Short, because
     * every command line carries it, and an atom, because the protocol demands
     * one.
     */
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private int $counter = 0;

    private function __construct(private readonly string $prefix)
    {
    }

    public static function random(): self
    {
        $alphabet = self::ALPHABET;
        $last = strlen($alphabet) - 1;

        return new self($alphabet[random_int(0, $last)] . $alphabet[random_int(0, $last)]);
    }

    /**
     * A fixed prefix, so that a test can assert on a whole command line.
     */
    public static function withPrefix(string $prefix): self
    {
        return new self($prefix);
    }

    public function next(): string
    {
        ++$this->counter;

        return $this->prefix . sprintf('%04d', $this->counter);
    }

    /**
     * The tag handed out last, without advancing. For a message about the
     * command currently in flight.
     */
    public function current(): string
    {
        return $this->counter === 0
            ? $this->prefix . '0000'
            : $this->prefix . sprintf('%04d', $this->counter);
    }
}

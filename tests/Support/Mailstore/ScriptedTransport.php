<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support\Mailstore;

use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Net\Transport;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;

/**
 * A transport that says what it was told to say and remembers what it heard.
 *
 * This is what makes the IMAP client testable without a server, and it is
 * deliberately byte-level: it hands out the exact bytes of a scripted session
 * and keeps the exact bytes the client wrote. A double that worked in terms of
 * "lines" would silently forgive the two mistakes worth testing for — a missing
 * CRLF and a literal whose announced length does not match its payload.
 *
 * A scripted line may contain {@see self::TAG}, which is replaced on the way out
 * with the tag of the command the client last sent. A real server echoes the
 * client's tag, and a test that cannot do that has to know the tag in advance —
 * which forces every connection under test to use a fixed prefix, and so leaves
 * the production path ({@see \Mailadmin\Infrastructure\Mailstore\Imap\CommandTag::random()})
 * untested.
 */
final class ScriptedTransport implements Transport
{
    /** Stands in for the tag of the command currently in flight. */
    public const string TAG = '~TAG~';

    /** Bytes the "server" has not handed over yet. */
    private string $inbound = '';

    private string $written = '';

    /** The tag of the last command line the client wrote. */
    private string $lastTag = '';

    private bool $encrypted;

    private bool $open = true;

    /** Set to make the next read behave like an expired deadline. */
    private bool $stalls = false;

    private function __construct(bool $encrypted)
    {
        $this->encrypted = $encrypted;
    }

    /**
     * A session that is already encrypted — the IMAPS case, and the default,
     * because it is the one the application uses.
     */
    public static function encrypted(string ...$lines): self
    {
        $transport = new self(true);
        $transport->willSay(...$lines);

        return $transport;
    }

    /**
     * A plaintext session, for the `STARTTLS` path and for asserting that
     * credentials are refused on it.
     */
    public static function plaintext(string ...$lines): self
    {
        $transport = new self(false);
        $transport->willSay(...$lines);

        return $transport;
    }

    /**
     * Queues further lines; each gets its CRLF.
     */
    public function willSay(string ...$lines): self
    {
        foreach ($lines as $line) {
            $this->inbound .= $line . self::CRLF;
        }

        return $this;
    }

    /**
     * Queues raw bytes with no terminator — a literal payload.
     */
    public function willSendBytes(string $bytes): self
    {
        $this->inbound .= $bytes;

        return $this;
    }

    /**
     * The next read times out, as a hung server would.
     */
    public function willStall(): self
    {
        $this->stalls = true;

        return $this;
    }

    /** Everything the client has written, verbatim. */
    public function written(): string
    {
        return $this->written;
    }

    /**
     * The written bytes as lines, CRLF stripped. Literal payloads land in here
     * as their own entry, which is usually what an assertion wants.
     *
     * @return list<string>
     */
    public function writtenLines(): array
    {
        $lines = explode(self::CRLF, $this->written);

        if (($lines[count($lines) - 1] ?? '') === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    public function hasUnread(): bool
    {
        return $this->inbound !== '';
    }

    public function readLine(): string
    {
        $this->guardReadable('a response line');

        $end = strpos($this->inbound, self::CRLF);

        if ($end === false) {
            // No terminator left: the peer stopped mid-line.
            throw ConnectionFailed::closed($this->endpoint(), 'a response line');
        }

        $line = substr($this->inbound, 0, $end);
        $this->inbound = substr($this->inbound, $end + strlen(self::CRLF));

        return str_replace(self::TAG, $this->lastTag, $line);
    }

    public function readBytes(int $count): string
    {
        $this->guardReadable('a literal');

        if (strlen($this->inbound) < $count) {
            throw ConnectionFailed::closed($this->endpoint(), 'a literal');
        }

        $bytes = substr($this->inbound, 0, $count);
        $this->inbound = substr($this->inbound, $count);

        return $bytes;
    }

    public function write(string $bytes): void
    {
        if (!$this->open) {
            throw ConnectionFailed::closed($this->endpoint(), 'a write');
        }

        $this->written .= $bytes;
        $this->rememberTag($bytes);
    }

    /**
     * Picks the tag out of a command line, the way a server does: the first
     * token, when it looks like one. A literal payload written after a
     * continuation has no tag and leaves the last one standing, which is right —
     * it is still the same command.
     */
    private function rememberTag(string $bytes): void
    {
        foreach (explode(self::CRLF, $bytes) as $line) {
            if (preg_match('/^([A-Za-z]+\d+) \S/', $line, $matches) === 1) {
                $this->lastTag = $matches[1];
            }
        }
    }

    public function enableCrypto(): void
    {
        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function endpoint(): string
    {
        return 'imap.test:993';
    }

    /**
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    private function guardReadable(string $during): void
    {
        if ($this->stalls) {
            $this->stalls = false;

            throw ReadTimedOut::whileReading($this->endpoint(), 30.0, $during);
        }

        if (!$this->open) {
            throw ConnectionFailed::closed($this->endpoint(), $during);
        }

        if ($this->inbound === '') {
            throw ConnectionFailed::closed($this->endpoint(), $during);
        }
    }
}

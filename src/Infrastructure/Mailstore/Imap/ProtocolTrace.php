<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Config\Environment;
use Psr\Log\LoggerInterface;

/**
 * A protocol transcript for debugging, with the one thing it must never contain
 * left out (M1, open point 6).
 *
 * A transcript is the difference between an afternoon and a week when a framing
 * bug appears. It is also the single most likely place for an administrator
 * password to end up in a log file, because the first two lines of every session
 * are the greeting and the login.
 *
 * Four rules, each of them enforced here rather than remembered by callers:
 *
 *  1. **Off unless switched on**, and it cannot be switched on in production.
 *     `MAILADMIN_IMAP_TRACE=1` plus `MAILADMIN_ENV != prod`.
 *  2. **A command is rendered by {@see ImapCommand::trace()}**, never from the
 *     bytes that go out. That rendering redacts secrets and reduces a literal to
 *     its length.
 *  3. **While a sensitive command is in flight, nothing sent is rendered.** SASL
 *     exchanges are base64 continuation lines with no structure to inspect — and
 *     `AUTHENTICATE PLAIN` puts the password in the first one.
 *  4. **Received lines are rendered by {@see ResponseLine::trace()}**, which
 *     shows literals as `{n}`. A literal in an answer can be a Sieve script, and
 *     a Sieve script can hold a vacation message.
 *
 * U-28's masking processor cannot help here: it looks at context field *names*,
 * and a transcript is one long string. The redaction has to happen before the
 * text becomes a log message, which is what this class is.
 */
final class ProtocolTrace
{
    public const string REDACTED = '<redacted>';

    /** `MAILADMIN_IMAP_TRACE` — never effective in `prod`. */
    public const string ENABLE = 'MAILADMIN_IMAP_TRACE';

    private bool $sensitiveInFlight = false;

    private function __construct(
        private readonly ?LoggerInterface $logger,
        private readonly string $endpoint,
    ) {
    }

    /**
     * A trace that writes nothing. The default everywhere.
     */
    public static function disabled(): self
    {
        return new self(null, '');
    }

    public static function to(LoggerInterface $logger, string $endpoint): self
    {
        return new self($logger, $endpoint);
    }

    /**
     * Enabled only outside production, and only when asked for.
     *
     * The environment check is not a courtesy. A transcript in production would
     * record every mailbox name of every customer into a log that is rotated,
     * copied and read by people who have no reason to see them.
     */
    public static function forEnvironment(
        LoggerInterface $logger,
        string $endpoint,
        Environment $environment,
        bool $requested,
    ): self {
        return $requested && !$environment->isProduction()
            ? self::to($logger, $endpoint)
            : self::disabled();
    }

    public function isEnabled(): bool
    {
        return $this->logger !== null;
    }

    public function sent(ImapCommand $command, string $tag): void
    {
        $this->sensitiveInFlight = $command->isSensitive();

        $this->write('C', $command->trace($tag));
    }

    /**
     * A continuation payload — a SASL response, or the bytes of a literal.
     *
     * Never rendered. Its length is the only thing worth knowing and the only
     * thing that cannot leak anything.
     */
    public function sentPayload(int $byteLength): void
    {
        $this->write('C', $this->sensitiveInFlight
            ? self::REDACTED
            : '<' . $byteLength . ' bytes>');
    }

    public function received(ResponseLine $line): void
    {
        $this->write('S', $line->trace());
    }

    /**
     * The command finished; the next payload is no longer part of it.
     */
    public function completed(): void
    {
        $this->sensitiveInFlight = false;
    }

    private function write(string $direction, string $text): void
    {
        $this->logger?->debug('imap ' . $direction . ': ' . $text, [
            'endpoint' => $this->endpoint,
        ]);
    }
}

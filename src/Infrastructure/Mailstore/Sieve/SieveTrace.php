<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Sieve;

use Mailadmin\Config\Environment;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Psr\Log\LoggerInterface;

/**
 * The ManageSieve transcript — {@see ProtocolTrace}'s rules, applied to a
 * protocol that carries something even more sensitive.
 *
 * The four rules are M1's and they are not restated here as advice but
 * implemented:
 *
 *  1. **Off unless switched on, and never effective in production.** The same
 *     switch as the IMAP transcript (`MAILADMIN_IMAP_TRACE`): one mailstore, one
 *     decision. Two variables would let an operator switch on the half that
 *     records vacation messages while believing the mailstore trace was off.
 *  2. **Commands are rendered from what we meant to send**, never from the bytes
 *     that went out.
 *  3. **An argument is shown only when it is short and plainly printable.**
 *     Script names qualify; a script does not, and appears as its length. The
 *     `AUTHENTICATE` exchange is redacted whole.
 *  4. **Received lines go through {@see SieveLine::trace()}**, which renders a
 *     literal as `{n}`.
 *
 * The third rule is the one this class exists for. A Sieve script is not merely
 * a configuration: it contains the out-of-office text a person wrote, and often
 * the addresses their mail is forwarded to. That belongs in the mailbox, not in
 * a log file that is rotated, copied and read.
 */
final class SieveTrace
{
    public const string REDACTED = ProtocolTrace::REDACTED;

    /** Beyond this an argument is reported as a length. */
    private const int SHOWN_MAX_LENGTH = 80;

    private function __construct(
        private readonly ?LoggerInterface $logger,
        private readonly string $endpoint,
    ) {
    }

    public static function disabled(): self
    {
        return new self(null, '');
    }

    public static function to(LoggerInterface $logger, string $endpoint): self
    {
        return new self($logger, $endpoint);
    }

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

    /**
     * @param list<string> $arguments
     */
    public function sent(string $command, array $arguments = [], bool $sensitive = false): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->write('C', $this->render($command, $arguments, $sensitive));
    }

    public function received(SieveLine $line): void
    {
        $this->write('S', $line->trace());
    }

    /**
     * @param list<string> $arguments
     */
    private function render(string $command, array $arguments, bool $sensitive): string
    {
        if ($sensitive) {
            // A SASL response is base64 with no structure to inspect, and
            // `AUTHENTICATE PLAIN` puts the administrator password in it.
            return $arguments === [] ? $command : $command . ' ' . self::REDACTED;
        }

        $rendered = $command;

        foreach ($arguments as $argument) {
            $rendered .= ' ' . self::renderArgument($argument);
        }

        return $rendered;
    }

    private static function renderArgument(string $argument): string
    {
        if (
            strlen($argument) <= self::SHOWN_MAX_LENGTH
            && preg_match('/[^\x20-\x7E]/', $argument) !== 1
        ) {
            return '"' . $argument . '"';
        }

        return '<' . strlen($argument) . ' bytes>';
    }

    private function write(string $direction, string $text): void
    {
        $this->logger?->debug('managesieve ' . $direction . ': ' . $text, [
            'endpoint' => $this->endpoint,
        ]);
    }
}

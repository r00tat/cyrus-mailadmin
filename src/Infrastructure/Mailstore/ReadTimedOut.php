<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

/**
 * A read or a connect exceeded its budget.
 *
 * **This is the failure mode the legacy client did not have.** It waited on a
 * socket with no timeout, so a Cyrus that accepted the connection and then said
 * nothing produced a PHP process that hung until the web server killed it — and
 * a mailbox operation whose outcome nobody knew. Every wait in this package has
 * a deadline, and running out of it is an exception, never a loop.
 */
final class ReadTimedOut extends MailstoreError
{
    public static function whileConnecting(string $endpoint, float $seconds): self
    {
        return new self(
            ErrorCode::TIMEOUT,
            sprintf('Connecting to %s took longer than %.1f s', $endpoint, $seconds),
        );
    }

    public static function whileReading(string $endpoint, float $seconds, string $during): self
    {
        return new self(
            ErrorCode::TIMEOUT,
            sprintf('%s did not answer %s within %.1f s', $endpoint, $during, $seconds),
        );
    }

    public static function whileWriting(string $endpoint, float $seconds, string $during): self
    {
        return new self(
            ErrorCode::TIMEOUT,
            sprintf('%s did not accept %s within %.1f s', $endpoint, $during, $seconds),
        );
    }

    /**
     * A timeout says nothing about whether the command ran. Retrying is
     * reasonable for a read, but the caller has to know the operation may have
     * taken effect — which is why A6 records the intent, not just the failure
     * (architecture 8.2).
     */
    public function isRetryable(): bool
    {
        return true;
    }
}

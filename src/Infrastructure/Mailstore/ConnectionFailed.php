<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Throwable;

/**
 * No usable connection — DNS, TCP, the TLS handshake or the certificate.
 *
 * Certificate failures get their own factory and their own code. They are the
 * one class of connection failure that must never be answered with "try again
 * without TLS": architecture 9.2 and finding S-5 exist because the legacy client
 * did exactly that.
 */
final class ConnectionFailed extends MailstoreError
{
    public static function socket(string $endpoint, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            ErrorCode::UNAVAILABLE,
            sprintf('Cannot connect to %s: %s', $endpoint, $reason),
            $previous,
        );
    }

    public static function tls(string $endpoint, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            ErrorCode::TLS_REJECTED,
            sprintf('TLS to %s was refused: %s', $endpoint, $reason),
            $previous,
        );
    }

    /**
     * The server dropped the connection while a command was in flight.
     */
    public static function closed(string $endpoint, string $during): self
    {
        return new self(
            ErrorCode::UNAVAILABLE,
            sprintf('%s closed the connection during %s', $endpoint, $during),
        );
    }

    public static function greetingRefused(string $endpoint, string $greeting): self
    {
        return new self(
            ErrorCode::UNAVAILABLE,
            sprintf('%s refused the connection in its greeting: %s', $endpoint, $greeting),
        );
    }

    /**
     * A dropped socket is the textbook case for a second attempt: nothing about
     * the request was wrong. A rejected certificate is not — retrying it would
     * only produce the same rejection, and treating it as transient is how a
     * verification failure turns into a warning nobody reads.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode() === ErrorCode::UNAVAILABLE;
    }
}

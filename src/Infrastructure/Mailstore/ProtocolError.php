<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

/**
 * The answer did not parse, or it did not belong to the command we sent.
 *
 * The wrong-tag case is the reason unique command tags exist (architecture 9.2).
 * With the legacy client's constant `.` an answer to the *previous* command
 * looked exactly like the answer to this one, so a stale line was read as a
 * result. Here it is an exception, because a mismatched tag means the stream is
 * no longer understood and everything after it would be a guess.
 */
final class ProtocolError extends MailstoreError
{
    public static function unexpectedTag(string $expected, string $received): self
    {
        return new self(
            ErrorCode::PROTOCOL_ERROR,
            sprintf('Expected the answer to tag %s, received tag %s', $expected, $received),
        );
    }

    public static function malformedLine(string $line): self
    {
        return new self(
            ErrorCode::PROTOCOL_ERROR,
            sprintf('Cannot parse the response line: %s', self::excerpt($line)),
        );
    }

    public static function truncatedLiteral(int $expected, int $received): self
    {
        return new self(
            ErrorCode::PROTOCOL_ERROR,
            sprintf('A literal announced %d bytes and delivered %d', $expected, $received),
        );
    }

    public static function literalTooLarge(int $announced, int $limit): self
    {
        return new self(
            ErrorCode::PROTOCOL_ERROR,
            sprintf('A literal announced %d bytes, more than the %d we accept', $announced, $limit),
        );
    }

    public static function continuationRefused(string $command, string $line): self
    {
        return new self(
            ErrorCode::PROTOCOL_ERROR,
            sprintf(
                '%s sent a literal and the server did not ask for it: %s',
                $command,
                self::excerpt($line),
            ),
        );
    }

    public static function unusableArgument(string $value, string $reason): self
    {
        return new self(
            ErrorCode::PROTOCOL_ERROR,
            sprintf('Cannot put "%s" on the wire: %s', self::excerpt($value), $reason),
        );
    }

    /**
     * Keeps a message short and free of control characters. Response text is
     * server output, and a stray CR in a log line splits it in two.
     */
    private static function excerpt(string $value): string
    {
        $flat = preg_replace('/[\x00-\x1F\x7F]/', '.', $value) ?? $value;

        return strlen($flat) > 120 ? substr($flat, 0, 117) . '...' : $flat;
    }
}

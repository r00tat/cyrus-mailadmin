<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;

/**
 * Every failure this layer raises, carrying a stable {@see ErrorCode}.
 *
 * Two rules shape the messages:
 *
 *  - **No secret ever appears in one.** A DSN is only ever embedded through
 *    {@see DatabaseConfiguration::redactedDsn()}; a password, a hash or a token
 *    never is. The message is written for an operator's log, and U-28 cannot
 *    help here — the masking processor cannot look inside a string.
 *  - **No message reaches a client.** The HTTP layer answers with the code, not
 *    with the text (`CLAUDE.md`, "Fehler").
 */
class PersistenceError extends RuntimeException
{
    /**
     * The property is called `errorCode` because `Exception::$code` already
     * exists and is an `int`.
     */
    final public function __construct(
        private readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The stable code (U-7). This, not the message, is what a caller reacts to.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function unavailable(string $redactedDsn, Throwable $previous): self
    {
        return new self(
            ErrorCode::UNAVAILABLE,
            sprintf('Cannot connect to the database (%s): %s', $redactedDsn, $previous->getMessage()),
            $previous,
        );
    }

    public static function queryFailed(string $statementName, Throwable $previous): self
    {
        return new self(
            ErrorCode::QUERY_FAILED,
            sprintf('Statement "%s" failed: %s', $statementName, $previous->getMessage()),
            $previous,
        );
    }

    public static function configuration(string $message): self
    {
        return new self(ErrorCode::CONFIGURATION_INVALID, $message);
    }

    public static function transactionMisuse(string $message): self
    {
        return new self(ErrorCode::TRANSACTION_MISUSE, $message);
    }
}

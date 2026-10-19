<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Mailadmin\Domain\Mailstore\MailstoreFailure;
use RuntimeException;
use Throwable;

/**
 * The root of every failure the mailstore layer raises.
 *
 * Modelled on {@see \Mailadmin\Infrastructure\Persistence\PersistenceError} and
 * bound by the same two rules:
 *
 *  - **No secret ever appears in a message.** U-28 masks log *context* by field
 *    name; it cannot look inside a string. A message that interpolated a
 *    password would therefore be logged in full. The factories below take a
 *    user name at most, never a password, never a hash, never a token.
 *  - **No message reaches a client.** The HTTP layer answers with the code.
 *
 * Subclasses exist so that a caller can distinguish the four cases M1 asks for —
 * connection, authentication, command, timeout — with `catch`, and so that a
 * retry policy (A6) does not have to read error codes out of a string.
 *
 * {@see MailstoreFailure} is the domain-side view of the same thing: it is what
 * `Domain\Mailstore\Mailstore` promises, so that the port does not have to name
 * an infrastructure class.
 */
abstract class MailstoreError extends RuntimeException implements MailstoreFailure
{
    /**
     * The property is called `errorCode` because `Exception::$code` already
     * exists and is an `int`.
     */
    public function __construct(
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

    /**
     * Is another attempt worth making?
     *
     * A6 puts what it cannot finish into `mailadmin_repair_queue`; this tells it
     * whether a retry has a chance. A refused command will be refused again — a
     * dropped connection may not be.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}

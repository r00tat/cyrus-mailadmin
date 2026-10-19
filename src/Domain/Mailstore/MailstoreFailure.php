<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Mailstore;

/**
 * What a {@see Mailstore} or {@see SieveStore} is allowed to throw.
 *
 * The port lives here, so its failure contract has to live here too. Without
 * this interface {@see Mailstore} would have to write
 * `@throws \Mailadmin\Infrastructure\Mailstore\MailstoreError` and the domain
 * layer would name an infrastructure class in the one place that is supposed to
 * be free of IMAP — including for the in-memory double, whose failures have
 * nothing to do with a socket at all.
 *
 * It is an interface rather than a base class because
 * `Infrastructure\Mailstore\MailstoreError` already extends `RuntimeException`,
 * and a second hierarchy would force the two apart for no gain.
 *
 * Implementations: `Infrastructure\Mailstore\MailstoreError` and its subclasses.
 */
interface MailstoreFailure extends \Throwable
{
    /**
     * The stable code (U-7), `mailstore.*` or `sieve.*`. This — not the message
     * — is what a caller branches on and what the HTTP layer maps to a status.
     */
    public function errorCode(): string;

    /**
     * Is another attempt worth making?
     *
     * A6 records what it could not finish in `mailadmin_repair_queue`; this says
     * whether replaying it has a chance. A refusal will be refused again; a
     * dropped connection may not be.
     */
    public function isRetryable(): bool;
}

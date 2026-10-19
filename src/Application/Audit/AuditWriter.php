<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * The one call every state-changing operation makes.
 *
 * CLAUDE.md: *jede zustandsändernde Operation schreibt nach `mailadmin_audit`*.
 * This is that write, and it is deliberately a single method taking a single
 * argument — a package adding an operation should not have to decide anything
 * about auditing except what to call the action.
 *
 * ```php
 * public function __construct(
 *     private DomainRepository $domains,
 *     private AuditWriter $audit,
 * ) {
 * }
 *
 * public function handle(ActorContext $actor, …): Domain
 * {
 *     …
 *     $this->audit->record(AuditEntry::creation($actor, 'domain.create', 'domain', $name, after: $view));
 * }
 * ```
 *
 * ## When to call it (U-63)
 *
 *  - **On success, inside the transaction that made the change.** Then the row
 *    and the change commit together, and a rolled-back operation leaves no entry
 *    claiming it happened.
 *  - **On a refusal or a failure, after the rollback.** There is nothing left to
 *    be atomic with, and writing inside the doomed transaction would throw the
 *    record away — which is the one entry an operator most wants.
 *  - **Never around the mailstore.** A transaction does not reach Cyrus. The
 *    entry records what the database ended up saying; a mailbox that could not be
 *    created belongs in `mailadmin_repair_queue` (A6), not in a second audit row.
 *
 * ## A failing write is not swallowed
 *
 * {@see record()} lets {@see PersistenceError} out. Called inside the operation's
 * transaction — where it belongs — that rolls the change back, which is the
 * intended reading of "every change is recorded": if the record cannot be
 * written, the change does not stand. The two places that must not fail that way
 * catch it themselves, because there the alternative is worse than losing a row:
 * the refusal recorder in the middleware, and the sign-in path, where a failing
 * audit write must not become an outage of the endpoint through which
 * administrators reach the application at all.
 *
 * @see \Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail for the double
 *      every use-case test uses
 */
interface AuditWriter
{
    /**
     * @return AuditEntryId so that the parts of a batch can point at it
     *
     * @throws PersistenceError when the entry could not be stored
     */
    public function record(AuditEntry $entry): AuditEntryId;
}

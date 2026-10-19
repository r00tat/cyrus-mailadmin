<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Policy\DomainPolicy;

/**
 * `PATCH /domains/{domain}` — the attributes, and the rename.
 *
 * The order of the four things this does is not arbitrary:
 *
 * ```text
 * 1. policy            — a role that may not write learns nothing about the domain
 * 2. read              — because everything after it needs the current state
 * 3. If-Match          — the client's picture has to be the current one
 * 4. rename, then the attributes
 * ```
 *
 * ## Why `If-Match` is required rather than accepted
 *
 * U-19 introduced the header as optional; A1 makes it mandatory on the two
 * operations that can destroy work (U-49). The reason is step 4: a rename is
 * decided by comparing the requested name with the stored one, and a stale
 * client that "renames" to a name the domain already has would either do nothing
 * or rename it away from what somebody else just set it to. A concurrency check
 * that is only used when a client remembers to send it is not a concurrency
 * check.
 *
 * A missing header is `428` and not `422`: `422` would say the request is
 * malformed, and it is not — it is incomplete in a way the client can fix by
 * reading the resource first, which is exactly what `428` means.
 *
 * ## Rename first, attributes second
 *
 * Both orders leave a window, and this is the cheaper one. Renaming first means
 * the attribute update addresses the domain under its new name and cannot miss;
 * updating first means writing to a row that the rename is about to move, and a
 * failed rename would leave attribute changes applied to a domain the caller
 * asked to be a different domain.
 *
 * ## Two operations, two audit records
 *
 * A `PATCH` that renames *and* changes attributes is two operations with two
 * failure modes, and the trail says so: {@see RenameDomain} records
 * `domain.rename` (with one child entry per account, U-61), and this class
 * records `domain.update` for the attribute change — one call each (U-64),
 * each inside the transaction of its own write (U-63). A rename that succeeds
 * followed by an attribute write that fails therefore leaves exactly the rename
 * in the trail, which is what the database then says.
 *
 * A `PATCH` whose attributes change nothing is still recorded when it was
 * written: the request was a write, and "somebody saved the form without
 * changing anything" is an answer an operator may be looking for.
 */
final readonly class UpdateDomain
{
    public function __construct(
        private DomainStore $store,
        private RenameDomain $rename,
        private DomainDefaults $defaults,
        private AuditWriter $audit,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Domain\Exception\DomainError
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(
        ActorContext $actor,
        DomainName $name,
        DomainChange $change,
        ?string $ifMatch,
    ): DomainSummary {
        DomainPolicy::requireWrite($actor, $name);

        $current = $this->store->find($name) ?? throw DomainOperationFailed::notFound($name);

        DomainPrecondition::require($current, $this->defaults, $ifMatch);

        $target = $change->renameTarget($name);

        if ($target !== null) {
            $current = $this->performRename($actor, $current, $target, $change->confirmsRename());
        }

        if ($change->attributes->isEmpty()) {
            return $current;
        }

        $updated = $current->with($change->attributes->applyTo($current->domain));

        $this->store->atomically(function () use ($actor, $current, $updated): void {
            $this->store->update($updated->domain);

            $this->audit->record(AuditEntry::change(
                $actor,
                'domain.update',
                'domain',
                $updated->name()->toString(),
                before: DomainView::full($current, $this->defaults),
                after: DomainView::full($updated, $this->defaults),
                domain: $updated->name(),
            ));
        });

        return $updated;
    }

    /**
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function performRename(
        ActorContext $actor,
        DomainSummary $current,
        DomainName $target,
        bool $confirmed,
    ): DomainSummary {
        if (!$confirmed) {
            // Before the existence check and before anything else: a caller who
            // did not mean to rename should not learn from the refusal whether
            // the name they typed by accident is taken.
            throw DomainOperationFailed::renameNotConfirmed($current->name(), $target);
        }

        if (!$current->domain->name->equals($target) && $this->store->exists($target)) {
            throw DomainOperationFailed::nameTaken($target);
        }

        $this->rename->handle($actor, $current->name(), $target);

        // Re-read rather than rebuild: the rename touched five tables, and the
        // representation the caller gets back should be what is now stored
        // rather than what this method believes it stored.
        return $this->store->find($target) ?? throw DomainOperationFailed::notFound($target);
    }
}

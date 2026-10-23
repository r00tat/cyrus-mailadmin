<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LegacyPrefix;
use Mailadmin\Domain\Policy\DomainPolicy;

/**
 * `POST /domains`.
 *
 * A domain has no mailbox — accounts do (A2) — so this is the one write in A1
 * that does not touch Cyrus at all, and the ordering rule of architecture 8.1
 * has nothing to order.
 *
 * ## `domain.prefix`
 *
 * The column is `NOT NULL UNIQUE` and nothing reads it; it cannot be removed
 * (E-1). So it is derived from the name rather than asked for (U-46), and the
 * uniqueness is resolved by trying the candidates {@see LegacyPrefix} offers in
 * order. The first is the domain name itself, which is what makes a row in
 * `domain` readable on its own.
 *
 * Running out of candidates is `domain.prefix_taken`, not a longer loop: at that
 * point the column holds values this application did not write, and a human
 * should look at it.
 *
 * ## The race this does not pretend to win
 *
 * {@see DomainStore::exists()} and the insert are two statements, and webcyradm
 * may be inserting between them. That is why the check is a courtesy and the
 * primary key is the guarantee: a concurrent insert makes this one fail with a
 * {@see \Mailadmin\Infrastructure\Persistence\PersistenceError} rather than
 * quietly overwriting a domain. The check exists so that the ordinary case — a
 * name somebody already used — gets `409` and a comprehensible message instead
 * of `500`.
 *
 * ## A subdomain whose user names are already taken (U-96)
 *
 * In the dotted scheme `info.kunde@example.de` is the Cyrus user
 * `info.kunde.example.de` — the name `info@kunde.example.de` would get. If
 * `example.de` already has that account, creating `kunde.example.de` makes the
 * login ambiguous. U-86 refuses the account when the subdomain is there first;
 * this refuses the subdomain when the account is there first
 * (`409 domain.namespace_conflict`). Whatever scheme the new domain asks for:
 * the scheme of a domain can change later, the user names cannot.
 *
 * ## Nor a name an earlier domain left rows behind for (U-99)
 *
 * `domainadmin` assignments and `virtual` rows ending in `@<name>` survive the
 * domain they belonged to when something other than this application removed
 * it. They would apply to the new domain at once — see
 * {@see DomainLeftovers}. `409 domain.leftover_state`.
 *
 * All checks run twice: once as a courtesy, and again under the namespace lock
 * that account creation takes too (U-97). The primary key stays the guarantee
 * against webcyradm, which knows nothing of the lock.
 *
 * ## Not the list transport domain
 *
 * Mail to the transport domain must reach the pipe transport. As a managed
 * domain Postfix would deliver it as a virtual domain instead, and every list
 * would stop. Any spelling (case, trailing dot) is `422 domain.invalid_name`.
 *
 * ## The audit entry is written inside the transaction
 *
 * U-63, as in A4's `CreateAdmin`: the `domain` row and the `mailadmin_audit` row
 * commit together, and a failing audit write takes the new domain with it. The
 * entry carries the representation `GET /domains/{domain}` would show, not the
 * database row (U-61).
 */
final readonly class CreateDomain
{
    public function __construct(
        private DomainStore $store,
        private DomainDefaults $defaults,
        private AuditWriter $audit,
        private ListDelivery $delivery,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Domain\Exception\DomainError
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, DomainDraft $draft): DomainSummary
    {
        DomainPolicy::requireCreate($actor);

        if ($this->delivery->isTransportDomain($draft->name)) {
            throw InvalidAddress::transportDomainName($draft->name->toString());
        }

        // The courtesy round: the ordinary refusal takes no lock.
        $this->requireFree($draft->name);

        $domain = $draft->toDomain($this->freePrefix($draft->name));
        $created = new DomainSummary($domain, 0);

        $this->store->withNamespaceLock(function () use ($actor, $domain, $created): void {
            // Again under the lock (U-97), which account creation takes for
            // its database step: an account that claimed this namespace in the
            // meantime is seen here, and one that has not yet waits.
            $this->requireFree($domain->name);

            $this->store->atomically(function () use ($actor, $domain, $created): void {
                $this->store->create($domain);

                $this->audit->record(AuditEntry::creation(
                    $actor,
                    'domain.create',
                    'domain',
                    $domain->name->toString(),
                    after: DomainView::full($created, $this->defaults),
                    domain: $domain->name,
                ));
            });
        });

        return $created;
    }

    /**
     * The name is not a domain yet, no account of a parent domain already
     * uses it as the tail of a dotted user name (U-96), and no earlier domain
     * of that name left assignments or addresses behind (U-99).
     *
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requireFree(DomainName $name): void
    {
        if ($this->store->exists($name)) {
            throw DomainOperationFailed::nameTaken($name);
        }

        $claimant = $this->store->namespaceClaimant($name);

        if ($claimant !== null) {
            throw DomainOperationFailed::namespaceConflict($name, $claimant);
        }

        DomainLeftovers::requireNone($this->store, $name);
    }

    /**
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function freePrefix(DomainName $name): string
    {
        foreach (LegacyPrefix::candidatesFor($name) as $candidate) {
            if (!$this->store->prefixTaken($candidate)) {
                return $candidate;
            }
        }

        throw DomainOperationFailed::prefixTaken($name);
    }
}

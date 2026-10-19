<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Policy\DomainPolicy;

/**
 * `GET /domains/{domain}`.
 *
 * **The order is the security property.** The policy runs first and answers for
 * a domain outside the actor's scope without the row ever being read; only then
 * is the table asked. Reversed, a domain master could tell an existing foreign
 * domain from a non-existent one by whether the refusal took a query — and the
 * whole point of answering `404` rather than `403` is that they cannot
 * (`CLAUDE.md`, U-44).
 *
 * The two refusals travel differently and end in the same place:
 * {@see \Mailadmin\Domain\Policy\ResourceNotVisible} is caught by
 * `AuthorizationMiddleware`, {@see DomainOperationFailed} by the handler, and
 * both are rendered by `ProblemDetails::notFound()` — one factory, so the two
 * documents are identical rather than merely similar.
 */
final readonly class GetDomain
{
    public function __construct(private DomainStore $store)
    {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, DomainName $name): DomainSummary
    {
        DomainPolicy::requireRead($actor, $name);

        return $this->store->find($name) ?? throw DomainOperationFailed::notFound($name);
    }
}

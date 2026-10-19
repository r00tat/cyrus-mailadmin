<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `GET /domains/{domain}/catchall`.
 */
final readonly class GetCatchall
{
    public function __construct(private CatchallLookup $lookup)
    {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, DomainName $domain): CatchallState
    {
        AddressPolicy::requireListIn($actor, $domain);

        return $this->lookup->of($domain);
    }
}

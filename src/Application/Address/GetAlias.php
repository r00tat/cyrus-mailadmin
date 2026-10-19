<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `GET /aliases/{alias}`. An address whose rows are all of another type — an
 * account's own address, say — is no alias and `404`.
 */
final readonly class GetAlias
{
    public function __construct(private AliasLookup $lookup)
    {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, string $alias): AliasState
    {
        $address = AliasLookup::address($alias);
        AddressPolicy::requireRead($actor, $address);

        return $this->lookup->existing($address);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Model\DomainName;

/**
 * `/domains/{domain}/catchall` → its rows. A domain without a catchall has an
 * empty one (`configured: false`); a name that is no domain and carries no
 * catchall row is `404`.
 */
final readonly class CatchallLookup
{
    public function __construct(
        private AddressReader $reader,
        private DomainStore $domains,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function of(DomainName $domain): CatchallState
    {
        $state = new CatchallState($domain, $this->reader->withAlias(CatchallState::alias($domain)));

        if ($state->rows->isEmpty() && !$this->domains->exists($domain)) {
            throw AddressOperationFailed::notFound('such domain');
        }

        return $state;
    }
}

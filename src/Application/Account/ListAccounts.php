<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Policy\AccountPolicy;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * `GET /domains/{domain}/accounts`.
 *
 * The policy runs before anything is read, as in A1's `GetDomain`: the domain is
 * in the path, so a foreign domain is refused without a query, and a missing
 * one is refused through the same `404` document (U-44).
 *
 * Nothing here asks Cyrus. Quota and usage are a resource of their own
 * (architecture 7.2).
 */
final readonly class ListAccounts
{
    public function __construct(
        private AccountStore $store,
        private DomainStore $domains,
    ) {
    }

    /**
     * @return Page<AccountRecord>
     *
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, DomainName $domain, ListCriteria $criteria): Page
    {
        AccountPolicy::requireListIn($actor, $domain);

        if (!$this->domains->exists($domain)) {
            throw AccountOperationFailed::notFound('such domain');
        }

        return $this->store->list($domain, $criteria);
    }
}

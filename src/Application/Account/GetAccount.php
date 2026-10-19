<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Domain\Access\ActorContext;

/**
 * `GET /accounts/{account}`. The lookup and the policy are
 * {@see AccountLocator}'s, including why the row is read first.
 */
final readonly class GetAccount
{
    public function __construct(private AccountLocator $locator)
    {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, string $id): AccountRecord
    {
        return $this->locator->forRead($actor, $id);
    }
}

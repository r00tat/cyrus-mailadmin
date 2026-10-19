<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Access\ActorContext;

/**
 * `GET /accounts/{account}/forwards`. An account without a forward has an
 * empty one (`configured: false`).
 */
final readonly class GetForwards
{
    public function __construct(
        private AccountTarget $accounts,
        private AddressReader $reader,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, string $account): ForwardState
    {
        $record = $this->accounts->forRead($actor, $account);

        return new ForwardState($record, $this->reader->forwardsOf($record->storedUsername));
    }
}

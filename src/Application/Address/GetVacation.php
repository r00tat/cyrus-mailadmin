<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `GET /accounts/{account}/vacation` — read from the active Sieve script
 * (U-102). A foreign or unreadable script is shown as it is, read-only.
 */
final readonly class GetVacation
{
    public function __construct(
        private AccountTarget $accounts,
        private VacationScripts $scripts,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, string $account): VacationState
    {
        $record = $this->accounts->forRead($actor, $account);
        $identity = AccountTarget::identityOf($record);
        AddressPolicy::requireRead($actor, $identity->address());

        return $this->scripts->state($record->storedUsername, $identity);
    }
}

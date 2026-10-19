<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * `/aliases/{alias}` → the address and its rows.
 *
 * A path that is not an address is the same `404` as an address without
 * rows: there is nothing to tell a client about either.
 */
final readonly class AliasLookup
{
    public function __construct(private AddressReader $reader)
    {
    }

    /**
     * @throws AddressOperationFailed
     */
    public static function address(string $path): EmailAddress
    {
        try {
            return EmailAddress::parseStored($path);
        } catch (DomainError) {
            throw AddressOperationFailed::notFound('such alias');
        }
    }

    /**
     * The rows under the address, in the spelling they are stored in.
     *
     * @throws AddressOperationFailed when the address has no alias row
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function existing(EmailAddress $address): AliasState
    {
        $state = new AliasState($address, $this->reader->withAlias($address->toString()));

        if (!$state->exists()) {
            throw AddressOperationFailed::notFound('such alias');
        }

        try {
            return new AliasState(EmailAddress::parseStored($state->aliases()[0]->alias()), $state->rows);
        } catch (DomainError) {
            return $state;
        }
    }
}

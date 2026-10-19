<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * One `domain` row: its name as stored and its `transport`.
 *
 * The transport matters for one check only. An address in a domain that Cyrus
 * delivers (`cyrus`, the legacy default) has to lead somewhere local; one in a
 * domain relayed elsewhere is somebody else's business.
 */
final readonly class InventoryDomain
{
    public const string LOCAL_TRANSPORT = 'cyrus';

    public function __construct(
        public string $name,
        public string $transport = self::LOCAL_TRANSPORT,
    ) {
    }

    public function isDeliveredLocally(): bool
    {
        return strtolower(trim($this->transport)) === self::LOCAL_TRANSPORT;
    }
}

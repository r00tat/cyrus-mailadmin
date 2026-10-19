<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * One `accountuser` row, exactly as stored: the user name in its stored bytes
 * and `domain_name` as text. Not an {@see \Mailadmin\Domain\Model\MailboxIdentity}
 * — a row that cannot become one is precisely something the reconciliation has
 * to report rather than fail on.
 */
final readonly class InventoryAccount
{
    public function __construct(
        public string $username,
        public string $domain,
    ) {
    }
}

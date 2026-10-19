<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Model\Virtual\VirtualEntry;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * One page of a domain's `virtual` entries, and the classification it came
 * from (for `sender_account`).
 */
final readonly class AddressListing
{
    /**
     * @param Page<VirtualEntry> $page
     */
    public function __construct(
        public Page $page,
        public ClassifiedRows $rows,
    ) {
    }
}

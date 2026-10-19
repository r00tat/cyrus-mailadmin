<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore;

use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Infrastructure\Mailstore\InMemorySieveStore;
use Mailadmin\Tests\Support\Mailstore\SieveStoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The double's half of M3's shared contract.
 *
 * {@see InMemorySieveStoreTest} keeps the cases that only a double can have —
 * the operation log, `givenForeignScript()`. This one runs the cases the real
 * timsieved has to pass as well, so that the two can be compared line by line
 * with {@see \Mailadmin\Tests\Integration\Mailstore\CyrusSieveStoreIntegrationTest}.
 */
#[CoversClass(InMemorySieveStore::class)]
final class InMemorySieveStoreContractTest extends SieveStoreContract
{
    protected function emptyStore(): SieveStore
    {
        return new InMemorySieveStore();
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

/**
 * Records the order in which middleware ran. Shared, mutable, and passed by
 * object identity — the only way an anonymous middleware can append to a list
 * the test can read afterwards.
 */
final class CallTrace
{
    /** @var list<string> */
    private array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}

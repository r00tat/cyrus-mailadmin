<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Mailadmin\Runtime\Sleeper;

/**
 * Notes down how long it was asked to wait, and waits for none of it.
 *
 * The growing delay in front of a failing sign-in is a real behaviour with a
 * real cap, and both halves deserve a test — the cap especially, because an
 * uncapped delay would turn the rate limiter into a way of spending the
 * PHP-FPM worker pool. Neither half is worth a test suite that actually sleeps.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    private array $requested = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->requested[] = $milliseconds;
    }

    /**
     * @return list<int>
     */
    public function requested(): array
    {
        return $this->requested;
    }

    public function longest(): int
    {
        return $this->requested === [] ? 0 : max($this->requested);
    }
}

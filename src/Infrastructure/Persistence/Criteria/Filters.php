<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * A compiled set of filter predicates: the `AND`-joined SQL and its bindings.
 *
 * Like {@see OrderBy}, the constructor is private and {@see FilterAllowlist} is
 * the only thing that can produce one. A repository receives a `Filters`; it
 * cannot make one from a request value.
 */
final readonly class Filters
{
    /**
     * @param array<string, scalar|null> $bindings
     */
    private function __construct(
        public string $sql,
        public array $bindings,
    ) {
    }

    /**
     * @param list<string>               $predicates
     * @param array<string, scalar|null> $bindings
     *
     * @internal only {@see FilterAllowlist} calls this
     */
    public static function of(array $predicates, array $bindings): self
    {
        return new self(implode(' AND ', $predicates), $bindings);
    }

    public static function none(): self
    {
        return new self('', []);
    }

    public function isEmpty(): bool
    {
        return $this->sql === '';
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Exception\InvalidDomainConfiguration;

/**
 * How many accounts a domain may hold — `domain.maxaccounts`.
 *
 * „Unlimited" is a state of its own, not the number zero. In the legacy column
 * a `0` *means* unlimited (webcyradm never enforced a limit of none), so
 * {@see fromLegacyValue()} maps it that way; a fresh limit of zero would have
 * to be spelled `AccountLimit::of(0)`, and it means „no account may be
 * created". **Assumption** — A1 owns the final answer to its open point 2 and
 * only has to change the factory, not the callers.
 */
final readonly class AccountLimit
{
    private function __construct(private ?int $maximum)
    {
    }

    public static function unlimited(): self
    {
        return new self(null);
    }

    /**
     * @throws InvalidDomainConfiguration
     */
    public static function of(int $maximum): self
    {
        if ($maximum < 0) {
            throw InvalidDomainConfiguration::negativeAccountLimit($maximum);
        }

        return new self($maximum);
    }

    /**
     * `domain.maxaccounts`, where `0` stands for „unlimited".
     *
     * @throws InvalidDomainConfiguration
     */
    public static function fromLegacyValue(int $value): self
    {
        return $value === 0 ? self::unlimited() : self::of($value);
    }

    public function isUnlimited(): bool
    {
        return $this->maximum === null;
    }

    public function maximum(): ?int
    {
        return $this->maximum;
    }

    /**
     * May one more account be created when `$current` exist?
     */
    public function permitsAnotherAccount(int $current): bool
    {
        return $this->maximum === null || $current < $this->maximum;
    }

    /**
     * Is the limit already undercut — after someone lowered it (A1, point 2)?
     */
    public function isExceededBy(int $current): bool
    {
        return $this->maximum !== null && $current > $this->maximum;
    }

    /**
     * The value for `domain.maxaccounts`.
     */
    public function toLegacyValue(): int
    {
        return $this->maximum ?? 0;
    }

    public function equals(self $other): bool
    {
        return $this->maximum === $other->maximum;
    }
}

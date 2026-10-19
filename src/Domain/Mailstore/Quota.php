<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Mailstore;

use Mailadmin\Domain\Exception\InvalidQuota;

/**
 * A storage quota, in the unit Cyrus uses (architecture 9.1).
 *
 * **The unit is kibibytes**, because that is what `SETQUOTA … STORAGE` takes.
 * Converting to MiB or GiB for display is the frontend's job (U-14 draws the
 * same line for time); the factories here exist so that no caller has to write
 * `* 1024` and get it wrong.
 *
 * **„Unlimited" is a state, not a magic number.** Cyrus distinguishes „no quota
 * root" from „a limit of zero", and so do we: `Quota::unlimited()` versus
 * `Quota::ofKibibytes(0)`, the latter meaning delivery fails immediately. The
 * legacy client turned the first into the string `NOT-SET`, which then travelled
 * through the code as data. The legacy *columns* (`domain.quota`,
 * `domain.domainquota`) use `0` for „unlimited", which is what
 * {@see fromLegacyKibibytes()} maps — **assumption**, confirmed by A1/M2.
 *
 * Usage is optional: a quota read from Cyrus knows it, a limit configured on a
 * domain does not. `usedKibibytes() === null` means „not measured", never zero.
 */
final readonly class Quota
{
    private function __construct(
        private ?int $limitKib,
        private ?int $usedKib,
    ) {
    }

    /**
     * No limit — in Cyrus terms: no quota root on this mailbox.
     *
     * @throws InvalidQuota
     */
    public static function unlimited(?int $usedKibibytes = null): self
    {
        return new self(null, self::guardUsage($usedKibibytes));
    }

    /**
     * @throws InvalidQuota
     */
    public static function ofKibibytes(int $limit, ?int $usedKibibytes = null): self
    {
        if ($limit < 0) {
            throw InvalidQuota::negativeLimit($limit);
        }

        return new self($limit, self::guardUsage($usedKibibytes));
    }

    /**
     * @throws InvalidQuota
     */
    public static function ofMebibytes(int $limit, ?int $usedKibibytes = null): self
    {
        return self::ofKibibytes(self::scale($limit, 1024, 'MiB'), $usedKibibytes);
    }

    /**
     * @throws InvalidQuota
     */
    public static function ofGibibytes(int $limit, ?int $usedKibibytes = null): self
    {
        return self::ofKibibytes(self::scale($limit, 1024 * 1024, 'GiB'), $usedKibibytes);
    }

    /**
     * A value from `domain.quota` or `domain.domainquota`, where `0` stands for
     * „unlimited".
     *
     * @throws InvalidQuota
     */
    public static function fromLegacyKibibytes(int $value): self
    {
        return $value === 0 ? self::unlimited() : self::ofKibibytes($value);
    }

    public function isUnlimited(): bool
    {
        return $this->limitKib === null;
    }

    public function limitKibibytes(): ?int
    {
        return $this->limitKib;
    }

    public function usedKibibytes(): ?int
    {
        return $this->usedKib;
    }

    public function hasUsage(): bool
    {
        return $this->usedKib !== null;
    }

    /**
     * Percentage used, or `null` when there is no limit or no measurement.
     */
    public function usagePercentage(): ?float
    {
        if ($this->limitKib === null || $this->usedKib === null) {
            return null;
        }

        if ($this->limitKib === 0) {
            // Any byte in a zero quota is over the limit; no division here.
            return $this->usedKib > 0 ? 100.0 : 0.0;
        }

        return $this->usedKib / $this->limitKib * 100.0;
    }

    public function isExceeded(): bool
    {
        if ($this->limitKib === null || $this->usedKib === null) {
            return false;
        }

        return $this->usedKib > $this->limitKib;
    }

    /**
     * Would `$kibibytes` more still fit? Unlimited and unmeasured both say yes
     * — the caller may not read „unknown" as „full".
     */
    public function accommodates(int $kibibytes): bool
    {
        if ($this->limitKib === null || $this->usedKib === null) {
            return true;
        }

        return $this->usedKib + $kibibytes <= $this->limitKib;
    }

    /**
     * @throws InvalidQuota
     */
    public function withUsage(int $usedKibibytes): self
    {
        return new self($this->limitKib, self::guardUsage($usedKibibytes));
    }

    /**
     * The value for `domain.quota` / `domain.domainquota`: `0` for unlimited.
     */
    public function toLegacyKibibytes(): int
    {
        return $this->limitKib ?? 0;
    }

    /**
     * Compares the limit only — usage is a measurement, not part of identity.
     */
    public function equals(self $other): bool
    {
        return $this->limitKib === $other->limitKib;
    }

    /**
     * @throws InvalidQuota
     */
    private static function guardUsage(?int $usedKibibytes): ?int
    {
        if ($usedKibibytes !== null && $usedKibibytes < 0) {
            throw InvalidQuota::negativeUsage($usedKibibytes);
        }

        return $usedKibibytes;
    }

    /**
     * @throws InvalidQuota
     */
    private static function scale(int $value, int $factor, string $unit): int
    {
        if ($value < 0) {
            throw InvalidQuota::negativeLimit($value);
        }

        if ($value > intdiv(PHP_INT_MAX, $factor)) {
            throw InvalidQuota::unitOverflow($value, $unit);
        }

        return $value * $factor;
    }
}

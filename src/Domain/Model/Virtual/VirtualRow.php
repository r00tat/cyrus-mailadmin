<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

/**
 * One row of the `virtual` table, exactly as it is stored.
 *
 * The row is carried through classification unchanged, so that every entry —
 * including one that fits no pattern — can be shown and written back without
 * loss (U-22). Nothing in the application guesses a meaning from these fields;
 * that happens once, in {@see VirtualEntryClassifier}.
 */
final readonly class VirtualRow
{
    /** Postfix only delivers rows with `status = 1`. */
    public const int STATUS_ENABLED = 1;

    public function __construct(
        public string $alias,
        public string $dest,
        public string $username,
        public int $status = self::STATUS_ENABLED,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    public function hasUsername(): bool
    {
        return trim($this->username) !== '';
    }

    public function equals(self $other): bool
    {
        return $this->alias === $other->alias
            && $this->dest === $other->dest
            && $this->username === $other->username
            && $this->status === $other->status;
    }
}

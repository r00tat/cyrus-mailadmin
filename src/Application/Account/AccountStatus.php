<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use DateTimeImmutable;

/**
 * Whether an account may log in — its row in `mailadmin_account_status`
 * (migration 0060, U-127).
 *
 * No row is an enabled account: everything webcyradm created has none. A
 * disabled account is left out of the view `allusers`, so `saslauthd` /
 * `pam_mysql` refuses it on every service that authenticates through the view.
 * Delivery is untouched.
 *
 * Distinct from {@see \Mailadmin\Domain\Model\AccountServices}: the four
 * service flags are carried and not enforced (U-78); this one is enforced.
 */
final readonly class AccountStatus
{
    /**
     * Public and defaulted to "enabled" so that it can be a parameter default
     * of {@see AccountRecord}; the named constructors say what is meant.
     */
    public function __construct(
        public bool $enabled = true,
        public ?DateTimeImmutable $disabledAt = null,
        public string $disabledBy = '',
    ) {
    }

    public static function enabled(): self
    {
        return new self(true, null, '');
    }

    /**
     * @param string $by the administrator who disabled it; empty when unknown
     */
    public static function disabled(?DateTimeImmutable $at, string $by): self
    {
        return new self(false, $at, $by);
    }

    public function equals(self $other): bool
    {
        return $this->enabled === $other->enabled;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

/**
 * The two roles the system knows (E-18, architecture 6).
 *
 * Deliberately two, and deliberately mapped onto permission sets rather than
 * checked directly: the legacy code decided authorisation in a 44 KB `switch`
 * that set a boolean per action. Which permissions a role carries is S3's
 * business; the role itself is a domain fact, because `adminuser.type` stores
 * it.
 */
enum AdminRole: string
{
    /** `adminuser.type = 0`. Sees every domain. */
    case Superuser = 'superuser';

    /** `adminuser.type = 1`. Sees the domains listed in `domainadmin`. */
    case DomainMaster = 'domainmaster';

    /** The wildcard row in `domainadmin` that stands for „all domains". */
    public const string ALL_DOMAINS = '*';

    /**
     * `adminuser.type`, where `0` means superuser and everything else does not.
     */
    public static function fromLegacyType(int $type): self
    {
        return $type === 0 ? self::Superuser : self::DomainMaster;
    }

    public function toLegacyType(): int
    {
        return $this === self::Superuser ? 0 : 1;
    }

    public function isSuperuser(): bool
    {
        return $this === self::Superuser;
    }
}

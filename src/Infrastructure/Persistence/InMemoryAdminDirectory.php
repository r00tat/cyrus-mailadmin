<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\AdminCredentials;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Access\DomainScope;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use SensitiveParameter;

/**
 * The administrator directory without a database.
 *
 * Ships in `src/` rather than in `tests/` for the same reason the mailstore
 * double does (U-37): it is what makes the sign-in and session use cases
 * testable at all, and a double that lives beside its interface stays in step
 * with it.
 *
 * It matches {@see SqlAdminDirectory} in the two behaviours that matter:
 *
 *  - user names are compared **byte for byte**, because `adminuser.username` is
 *    `latin1_bin`. A double that folded case would hide a defect the real table
 *    would not.
 *  - a domain master with no domains yields `null` from {@see findActor()},
 *    not an exception and not an unrestricted actor.
 */
final class InMemoryAdminDirectory implements AdminDirectory
{
    /** @var array<string, AdminCredentials> */
    private array $admins = [];

    /** @var array<string, list<string>> */
    private array $scopes = [];

    /** @var list<string> */
    private array $rehashed = [];

    /**
     * @param list<string> $domains ignored for a superuser, as in the real table
     */
    public function given(
        string $username,
        #[SensitiveParameter] string $passwordHash,
        AdminRole $role = AdminRole::Superuser,
        array $domains = [],
    ): self {
        $this->admins[$username] = new AdminCredentials($username, $passwordHash, $role);
        $this->scopes[$username] = $domains;

        return $this;
    }

    public function forget(string $username): self
    {
        unset($this->admins[$username], $this->scopes[$username]);

        return $this;
    }

    public function findCredentials(string $username): ?AdminCredentials
    {
        return $this->admins[$username] ?? null;
    }

    public function findActor(string $username): ?ActorContext
    {
        $admin = $this->admins[$username] ?? null;

        if ($admin === null) {
            return null;
        }

        if ($admin->role->isSuperuser()) {
            return ActorContext::superuser($username);
        }

        $domains = array_map(
            static fn (string $name): DomainName => DomainName::fromStoredValue($name),
            $this->scopes[$username] ?? [],
        );

        if ($domains === []) {
            return null;
        }

        return ActorContext::of($username, $admin->role, DomainScope::of(...$domains));
    }

    public function replacePasswordHash(string $username, #[SensitiveParameter] string $hash): void
    {
        $existing = $this->admins[$username] ?? null;

        if ($existing === null) {
            return;
        }

        $this->admins[$username] = new AdminCredentials($username, $hash, $existing->role);
        $this->rehashed[] = $username;
    }

    /**
     * Who had their hash rewritten, in order. E-13 says a legacy hash is lifted
     * on a successful sign-in and only then — a test that cannot see this cannot
     * tell "rehashed once" from "rehashed on every request".
     *
     * @return list<string>
     */
    public function rehashed(): array
    {
        return $this->rehashed;
    }

    /**
     * The stored hash, so a test can assert what was written without reaching
     * through {@see findCredentials()} and pretending to be the sign-in path.
     */
    public function storedHash(string $username): ?string
    {
        return ($this->admins[$username] ?? null)?->passwordHash;
    }
}

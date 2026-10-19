<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Application\Admin\AdminAccount;
use Mailadmin\Application\Admin\AdminStore;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\PasswordFormatDetector;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use SensitiveParameter;

/**
 * {@see AdminStore} without a database, for the use-case tests.
 *
 * A4's rules are counting rules — the last superuser, self-deletion, the
 * role/scope agreement — and each of them is a branch that has to be exercised.
 * Against MariaDB that means a suite that skips itself wherever there is no
 * container; here it is a few lines and always runs.
 *
 * ## It has to be wrong in the same ways
 *
 * A double that is *nicer* than the real thing tests nothing, so this one
 * reproduces the behaviour that matters:
 *
 *  - **byte comparison** on the user name, because `adminuser.username` is
 *    `latin1_bin` and `Admin` is not `admin`;
 *  - the **wildcard row** `*` as a scope rather than a domain name;
 *  - **orphaned assignments** — a domain that was never registered here stays in
 *    the list and is reported (U-22);
 *  - an **unknown filter or sort key raises**, exactly as
 *    {@see Criteria\FilterAllowlist} and {@see Criteria\SortAllowlist} do. A
 *    double that quietly ignored one would hide the defect the allowlists exist
 *    to catch.
 *
 * **It is not wired into the container**, and neither is S2's directory double,
 * for the same reason (U-37): a container that could be talked into an in-memory
 * administrator table by an environment variable is one variable away from an
 * installation where deleting an administrator does nothing.
 */
final class InMemoryAdminStore implements AdminStore
{
    /** @var array<string, array{hash: string, role: AdminRole, all: bool, domains: list<string>}> */
    private array $admins = [];

    /** @var list<string> */
    private array $domains = [];

    /**
     * @param list<string> $domains named assignments; the wildcard is `$allDomains`
     */
    public function given(
        string $username,
        #[SensitiveParameter] string $passwordHash,
        AdminRole $role,
        array $domains = [],
        ?bool $allDomains = null,
    ): self {
        $this->admins[$username] = [
            'hash' => $passwordHash,
            'role' => $role,
            'all' => $allDomains ?? $role->isSuperuser(),
            'domains' => array_values($domains),
        ];

        return $this;
    }

    /**
     * Register a domain so that {@see existingDomains()} knows it. A domain that
     * is assigned but never registered here is what an orphaned assignment is.
     */
    public function withDomain(string ...$names): self
    {
        foreach ($names as $name) {
            if (!in_array($name, $this->domains, true)) {
                $this->domains[] = $name;
            }
        }

        return $this;
    }

    /**
     * The stored hash, for a test that wants to assert what was written without
     * going through {@see findPasswordHash()}'s contract.
     */
    public function storedHash(string $username): ?string
    {
        return $this->admins[$username]['hash'] ?? null;
    }

    public function list(ListCriteria $criteria): Page
    {
        $matching = [];

        foreach ($this->admins as $username => $admin) {
            if ($this->matches($username, $admin['role'], $criteria)) {
                $matching[] = $this->accountFor($username);
            }
        }

        self::sortAccounts($matching, $criteria);

        return Page::of(
            array_slice($matching, $criteria->offset(), $criteria->perPage),
            $criteria,
            count($matching),
        );
    }

    public function find(string $username): ?AdminAccount
    {
        return isset($this->admins[$username]) ? $this->accountFor($username) : null;
    }

    public function exists(string $username): bool
    {
        return isset($this->admins[$username]);
    }

    public function findPasswordHash(string $username): ?string
    {
        return $this->admins[$username]['hash'] ?? null;
    }

    public function countSuperusers(): int
    {
        $count = 0;

        foreach ($this->admins as $admin) {
            if ($admin['role']->isSuperuser()) {
                ++$count;
            }
        }

        return $count;
    }

    public function existingDomains(array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name): bool => in_array($name, $this->domains, true),
        ));
    }

    public function create(
        string $username,
        #[SensitiveParameter] string $passwordHash,
        AdminRole $role,
        array $domains,
    ): void {
        $this->given($username, $passwordHash, $role, $domains);
    }

    public function replaceAssignment(string $username, AdminRole $role, array $domains): void
    {
        if (!isset($this->admins[$username])) {
            return;
        }

        $this->admins[$username]['role'] = $role;
        $this->admins[$username]['all'] = $role->isSuperuser();
        $this->admins[$username]['domains'] = array_values($domains);
    }

    public function replacePasswordHash(string $username, #[SensitiveParameter] string $hash): void
    {
        if (!isset($this->admins[$username])) {
            return;
        }

        $this->admins[$username]['hash'] = $hash;
    }

    public function delete(string $username): void
    {
        unset($this->admins[$username]);
    }

    public function atomically(callable $work): mixed
    {
        // No rollback, and no pretence of one. A test that needs to see a
        // half-written state writes it directly; a double that simulated
        // transactions would be asserting its own implementation.
        return $work();
    }

    private function accountFor(string $username): AdminAccount
    {
        $admin = $this->admins[$username];
        $named = $admin['domains'];
        sort($named);

        $orphaned = array_values(array_filter(
            $named,
            fn (string $domain): bool => !in_array($domain, $this->domains, true),
        ));

        return new AdminAccount(
            username: $username,
            role: $admin['role'],
            allDomains: $admin['all'],
            domains: $named,
            orphanedDomains: $orphaned,
            passwordFormat: PasswordFormatDetector::detect($admin['hash']),
        );
    }

    /**
     * @throws CriteriaError on a filter key the repository does not offer
     */
    private function matches(string $username, AdminRole $role, ListCriteria $criteria): bool
    {
        /** @var mixed $value */
        foreach ($criteria->filters as $key => $value) {
            $matched = match ($key) {
                'username' => str_contains($username, (string) (is_scalar($value) ? $value : '')),
                'role' => $role->toLegacyType() === (int) (is_numeric($value) ? $value : -1),
                default => throw CriteriaError::unknownFilterField($key, ['username', 'role']),
            };

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<AdminAccount> $accounts
     *
     * @throws CriteriaError on a sort key the repository does not offer
     */
    private static function sortAccounts(array &$accounts, ListCriteria $criteria): void
    {
        $field = $criteria->sort ?? 'username';

        if (!in_array($field, ['username', 'role'], true)) {
            throw CriteriaError::unknownSortField($field, ['username', 'role']);
        }

        $descending = $criteria->order === SortDirection::Descending;

        usort($accounts, static function (AdminAccount $left, AdminAccount $right) use ($field, $descending): int {
            // The tiebreaker is the user name, as SortAllowlist appends it —
            // without it a page boundary can show the same row twice.
            $result = $field === 'role'
                ? $left->role->toLegacyType() <=> $right->role->toLegacyType()
                : 0;

            if ($result === 0) {
                $result = strcmp($left->username, $right->username);

                return $descending && $field === 'username' ? -$result : $result;
            }

            return $descending ? -$result : $result;
        });
    }
}

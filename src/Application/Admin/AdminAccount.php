<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\PasswordFormat;

/**
 * One administrator, as the API shows them: `adminuser` joined with
 * `domainadmin`.
 *
 * **It carries no hash.** The stored password is read only where it is needed —
 * {@see AdminStore::findPasswordHash()} for verifying a current password — and
 * the *format* travels here instead. That is not decoration: this object is
 * built for every row of a listing and ends up in a JSON body, and a hash that
 * is never in the object cannot be serialised into one by accident.
 *
 * ## Three fields that describe the database rather than an intention
 *
 * U-22 says a legacy state is shown, not filtered away, so:
 *
 *  - {@see $allDomains} is the wildcard row `domainadmin.domain_name = '*'`.
 *    Normally true exactly for a superuser — but an installation may carry it
 *    for a domain master, and U-43 honours that, so the field is read rather
 *    than derived from the role.
 *  - {@see $orphanedDomains} names assignments pointing at a domain that has no
 *    row in `domain`. A subset of {@see $domains}, never a separate set: the
 *    assignment is shown where it is, and marked.
 *  - {@see $passwordFormat} is the format of the stored hash. A legacy one keeps
 *    working and becomes Argon2id at the next sign-in (E-13); until then it is
 *    visible here instead of invisible everywhere.
 */
final readonly class AdminAccount
{
    /**
     * @param list<string> $domains         named assignments, sorted, wildcard excluded
     * @param list<string> $orphanedDomains subset of `$domains` with no row in `domain`
     */
    public function __construct(
        public string $username,
        public AdminRole $role,
        public bool $allDomains,
        public array $domains,
        public array $orphanedDomains,
        public PasswordFormat $passwordFormat,
    ) {
    }

    /**
     * Exactly the `Admin` schema of the contract, in a fixed key order.
     *
     * **One method, two consumers**: the JSON body and the `ETag`. U-19 hashes
     * the representation rather than the database row, so that a column
     * webcyradm writes in parallel does not invalidate a client's validator —
     * and that only holds if the hash is taken over the same array that is sent.
     *
     * @return array{
     *     username: string,
     *     role: string,
     *     all_domains: bool,
     *     domains: list<string>,
     *     orphaned_domains: list<string>,
     *     password_format: string,
     *     password_legacy: bool
     * }
     */
    public function representation(): array
    {
        return [
            'username' => $this->username,
            'role' => $this->role->value,
            'all_domains' => $this->allDomains,
            'domains' => $this->domains,
            'orphaned_domains' => $this->orphanedDomains,
            'password_format' => $this->passwordFormat->value,
            'password_legacy' => $this->passwordFormat->isLegacy(),
        ];
    }

    public function isSuperuser(): bool
    {
        return $this->role->isSuperuser();
    }
}

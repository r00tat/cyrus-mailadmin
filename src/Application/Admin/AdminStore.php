<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;
use SensitiveParameter;
use Throwable;

/**
 * Everything A4 does to `adminuser`, `domainadmin`, `settings` and
 * `mailadmin_admin_setting`.
 *
 * A port rather than a bare repository call, for the reason the mailstore has
 * one (U-37) and S2's {@see \Mailadmin\Domain\Access\AdminDirectory} has one:
 * the rules worth testing here — the last superuser, self-deletion, the
 * role/scope agreement — are counting rules, and a rule that can only be
 * exercised with MariaDB running is a rule that gets exercised rarely. The SQL
 * lives in {@see \Mailadmin\Infrastructure\Persistence\AdminRepository}, the
 * double in {@see \Mailadmin\Infrastructure\Persistence\InMemoryAdminStore}.
 *
 * ## Not the same port as S2's
 *
 * {@see \Mailadmin\Domain\Access\AdminDirectory} answers "who is signing in and
 * what may they see" and is on the authentication path. This one administers the
 * same tables. Keeping them apart means the object the sign-in depends on stays
 * three methods long, and A4 cannot widen it by accident.
 *
 * The one place they touch is {@see replacePasswordHash()}, which both need for
 * different reasons — S2 to lift a legacy hash at sign-in (E-13), A4 to set a
 * new password.
 *
 * ## Case sensitivity is the table's
 *
 * `adminuser.username` is `latin1_bin`, so every comparison here is a byte
 * comparison and `Admin` is not `admin`. That is webcyradm's behaviour and the
 * accounts in an existing installation were created under it; folding case would
 * let one name act as another.
 */
interface AdminStore
{
    /**
     * One page of administrators.
     *
     * **Not scoped by actor, and that is not an omission.** `Repository::scopedTo()`
     * narrows a listing to the actor's domains, and this listing has no domain
     * column to narrow on — an administrator is not *in* a domain. The narrowing
     * that does apply is the permission: `admin.read` is superuser-only (U-43),
     * so whoever reaches this method sees everything there is by definition.
     *
     * @return Page<AdminAccount>
     */
    public function list(ListCriteria $criteria): Page;

    public function find(string $username): ?AdminAccount;

    public function exists(string $username): bool;

    /**
     * The stored hash, for verifying a password that was typed in.
     *
     * Deliberately not part of {@see AdminAccount}: that object is built per
     * row of a listing and serialised into a response, and a hash that never
     * enters it cannot leave in one.
     */
    public function findPasswordHash(string $username): ?string;

    /**
     * How many administrators have `type = 0` right now.
     *
     * The number, not a boolean: "is this the last one" is a question about the
     * table at this moment, and the use case is the place that knows what to do
     * with the answer.
     */
    public function countSuperusers(): int;

    /**
     * Which of `$names` have a row in `domain`, in the order given.
     *
     * The complement is what {@see AdminOperationFailed::unknownDomains()}
     * reports. Asking for the known ones rather than the unknown ones keeps the
     * single query straightforward and leaves the diffing to the caller.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public function existingDomains(array $names): array;

    /**
     * The `adminuser` row and its `domainadmin` assignments, in one go.
     *
     * @param list<string> $domains named domains; ignored for a superuser, which
     *                              is written with the wildcard row instead
     */
    public function create(
        string $username,
        #[SensitiveParameter] string $passwordHash,
        AdminRole $role,
        array $domains,
    ): void;

    /**
     * Replace role and assignment together.
     *
     * One method rather than two, because the two cannot disagree: a superuser
     * is stored as the wildcard row and a domain master as one row per domain,
     * so writing the role without rewriting the assignment would produce a state
     * neither this application nor webcyradm would create.
     *
     * @param list<string> $domains
     */
    public function replaceAssignment(string $username, AdminRole $role, array $domains): void;

    public function replacePasswordHash(string $username, #[SensitiveParameter] string $hash): void;

    /**
     * The `adminuser` row and everything else keyed by the same name:
     * `domainadmin`, the legacy `settings` preferences and
     * `mailadmin_admin_setting`.
     *
     * webcyradm deleted the `settings` row along with the administrator, and A4
     * keeps that. Leaving it would hand the preferences of a departed
     * administrator to the next person who happens to be given the same name.
     */
    public function delete(string $username): void;

    /**
     * Run `$work` in one transaction.
     *
     * Named `atomically` rather than `transactional` so that the SQL
     * implementation can extend {@see \Mailadmin\Infrastructure\Persistence\Repository},
     * whose own `transactional()` hands the callable a connection. Two methods
     * with one name and different callables would be a needless override.
     *
     * Every write above is a multi-statement change across two or four tables,
     * and half of one is worse than none of it: an `adminuser` row without its
     * `domainadmin` rows is an administrator who cannot sign in.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws Throwable
     */
    public function atomically(callable $work): mixed;
}

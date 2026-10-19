<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;

/**
 * What may be done to an administrator account — A4's gate.
 *
 * The one family that is **not domain-scoped**. An administrator is not in a
 * domain: `adminuser` has no domain column, and the assignment in `domainadmin`
 * is a list, not an owner. So there is no `404` case here — `admin.*` is
 * superuser-only, and a domain master is refused at the permission, before any
 * lookup happens and therefore before there is anything to hide.
 *
 * ## Two rules that are not this package's to enforce
 *
 * A4 keeps them, and they are noted here so that nobody expects to find them in
 * a policy: **the last superuser** may be neither deleted nor demoted, and
 * **self-deletion** is refused. Both need to count rows in `adminuser`, which
 * makes them use-case rules rather than policy rules — a policy that read the
 * database would be a policy that could not be tested without one.
 *
 * ## One's own password is not administration
 *
 * {@see requireOwnPasswordChange()} answers `PUT /admins/me/password`, which
 * both roles reach. webcyradm had this right: `changeadminpasswd` was the single
 * screen with no role check at all. A domain master who had to ask a superuser
 * for a new password would be handing their account to somebody who then knows
 * the password.
 */
final class AdminPolicy
{
    private function __construct()
    {
    }

    /**
     * @throws AccessDenied
     */
    public static function requireRead(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::AdminRead);
    }

    /**
     * @throws AccessDenied
     */
    public static function requireWrite(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::AdminWrite);
    }

    /**
     * @throws AccessDenied
     */
    public static function requireDelete(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::AdminDelete);
    }

    /**
     * Changing the password of `$username`, where the only permitted value of
     * `$username` is the actor's own name.
     *
     * A superuser resetting **somebody else's** administrator password is
     * {@see requireWrite()}, not this — the two are different operations with
     * different endpoints, and merging them here would let
     * `profile.password.write`, which every role holds, reach another account.
     *
     * The comparison is `hash_equals` rather than `===` for the same reason
     * every credential comparison in this codebase is: the cost of writing it is
     * nothing and the habit is what protects the case where it matters.
     *
     * @throws AccessDenied
     */
    public static function requireOwnPasswordChange(ActorContext $actor, string $username): void
    {
        Guard::permission($actor, Permission::ProfilePasswordWrite);

        if (!self::isSelf($actor, $username)) {
            throw AccessDenied::notTheSubject($actor, Permission::ProfilePasswordWrite, $username);
        }
    }

    /**
     * Reading one's own interface preferences (W2). There is no subject
     * argument: the preferences read are always the actor's own, and there is
     * no endpoint that names anybody else's.
     *
     * @throws AccessDenied
     */
    public static function requireOwnPreferencesRead(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::ProfilePreferencesRead);
    }

    /**
     * Changing one's own interface preferences (W2). As above, always the
     * actor's own.
     *
     * @throws AccessDenied
     */
    public static function requireOwnPreferencesWrite(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::ProfilePreferencesWrite);
    }

    /**
     * Is this name the actor's own?
     *
     * A byte comparison, because `adminuser.username` is `latin1_bin` and the
     * database treats `Admin` and `admin` as two accounts. Folding case here
     * would let one of them act as the other.
     */
    public static function isSelf(ActorContext $actor, string $username): bool
    {
        return hash_equals($actor->username, $username);
    }

    public static function mayManage(ActorContext $actor): bool
    {
        return Guard::allows($actor, Permission::AdminWrite);
    }
}

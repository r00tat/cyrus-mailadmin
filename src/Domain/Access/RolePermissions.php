<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Access;

use Mailadmin\Domain\Model\AdminRole;

/**
 * Which permissions each of the two roles holds (E-18, architecture 6).
 *
 * The roles stay two. Mapping them onto permission sets rather than checking
 * `role === Superuser` at each site costs nothing and means a third role would
 * be one entry here instead of a search through the handlers — which is the
 * shape the legacy `switch` had, and the reason it grew to 44 KB.
 *
 * ## Where the split comes from
 *
 * Not invented. It is what webcyradm enforced, read out of
 * `validate.inc.php`: `newdomain`, `editdomain`, `deletedomain` and the whole
 * `adminuser` family began with `if ($_SESSION['admintype'] != 0)` and refused;
 * accounts, aliases, addresses, catchall, forwards and vacation had no role
 * check at all and were bounded only by the domain list; `changeadminpasswd`
 * was open to everyone. Keeping that split means no administrator loses an
 * ability they have today, and none gains one.
 *
 * Two departures from it, both deliberate:
 *
 *  - **`domain.read` is granted to a domain master.** webcyradm's "browse
 *    domains" was superuser-only, but a domain master could still see their
 *    domain's account limit and quota on every account screen, because the UI
 *    read them to render the form. Making that a permission is honest about
 *    something that was already true. It is scoped: the list contains their
 *    domains and no others.
 *  - **`list.*` is superuser-only.** webcyradm had no list management at all —
 *    `lists` is written by Salt — so there is no behaviour to preserve and
 *    deny-by-default decides. A5 may widen it; widening later takes nothing
 *    away from anyone, and narrowing later would.
 *
 * `audit.read` and `maintenance.*` are likewise superuser-only for want of
 * anything to preserve: an audit trail names administrators across domains, and
 * a reconciliation run compares the whole mail store against the whole database.
 */
final class RolePermissions
{
    private function __construct()
    {
    }

    public static function of(AdminRole $role): PermissionSet
    {
        return $role->isSuperuser() ? self::superuser() : self::domainMaster();
    }

    /**
     * Everything, by definition — not an enumeration.
     *
     * A written-out list would mean that a package adding a permission and
     * forgetting this file produces a superuser who cannot use it, and the
     * symptom of that is a `403` for the one role that is supposed to have none.
     */
    public static function superuser(): PermissionSet
    {
        return PermissionSet::everything();
    }

    public static function domainMaster(): PermissionSet
    {
        return PermissionSet::of(
            // The session, one's own password and one's own interface
            // preferences: not privileges.
            Permission::SessionRead,
            Permission::SessionWrite,
            Permission::ProfilePasswordWrite,
            Permission::ProfilePreferencesRead,
            Permission::ProfilePreferencesWrite,

            // Their domains — readable, not writable. Creating, renaming and
            // deleting a domain stay with the superuser: a rename walks every
            // mailbox in Cyrus (CLAUDE.md, order of operations), and a delete
            // removes mail.
            Permission::DomainRead,

            // Accounts and addresses inside those domains: the daily work, and
            // exactly what webcyradm let them do. Every one of these is
            // additionally scoped by a policy — the permission says "this kind
            // of operation", the policy says "this object".
            Permission::AccountRead,
            Permission::AccountWrite,
            Permission::AccountDelete,
            Permission::AccountPasswordWrite,
            Permission::AddressRead,
            Permission::AddressWrite,
            Permission::AddressDelete,
        );
    }
}

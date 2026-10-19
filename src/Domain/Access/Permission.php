<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Access;

/**
 * Every permission the API knows — the authoritative list (architecture 6).
 *
 * The legacy system decided authorisation in a 44 KB `switch` that set a boolean
 * per action. There was no list: to find out what a role could do you read the
 * whole file. This enum **is** the list, and the contract may not name a
 * permission that is not in it — `Http\Routing\RouteCompiler` refuses to compile
 * a route table otherwise, so a typo in `x-permission` is a build failure rather
 * than an operation nobody can reach.
 *
 * (Named in prose rather than with `{@see}`: the domain model does not import
 * from the layers above it, and an import for a doc link would be one all the
 * same.)
 *
 * ## The form
 *
 * `<resource>.<verb>`, both parts lower case, as `openapi/README.md` (5)
 * requires. A resource may have a sub-resource before the verb
 * (`account.password.write`) when the finer grain is worth a permission of its
 * own — it is worth it exactly when one could sensibly hold the coarse right
 * without the fine one.
 *
 * ## One family per package
 *
 * `domain.*` is A1, `account.*` is A2, `address.*` is A3, `admin.*` is A4,
 * `list.*` is A5, `maintenance.*` is A6, `audit.*` is S4, `session.*` is S2.
 * That is not decoration: a package that needs a new permission adds it to its
 * own family and nowhere near anyone else's, and the reviewer of an A-package
 * diff can see at a glance whether it granted itself something outside its
 * subject.
 *
 * ## `public` is in here, and is not a permission
 *
 * It is the declaration that **none** is needed, and it is a case so that this
 * enum is the complete set of legal `x-permission` values — one place to check
 * against, one `from()` in the compiler. No role holds it;
 * {@see PermissionSet} refuses to contain it.
 */
enum Permission: string
{
    /**
     * No token required. Pairs with `security: []` in the contract; one without
     * the other is a contract-test failure.
     */
    case PublicAccess = 'public';

    // ------------------------------------------------------------------
    // S2 — the session itself. Both roles hold both: signing out and asking
    // who you are are not privileges, they are the minimum an authenticated
    // caller can do with their own session.
    // ------------------------------------------------------------------

    /** `GET /auth/me`. */
    case SessionRead = 'session.read';

    /** `POST /auth/logout`. */
    case SessionWrite = 'session.write';

    // ------------------------------------------------------------------
    // A4 — one's own administrator account, as opposed to everybody else's.
    // ------------------------------------------------------------------

    /**
     * `PUT /admins/me/password`. Held by **both** roles.
     *
     * Deliberately not part of `admin.write`: changing your own password is not
     * administering other people, and a domain master who cannot do it has to
     * ask a superuser to set a password that the superuser then knows. webcyradm
     * had this right — `changeadminpasswd` was the one screen with no role check.
     */
    case ProfilePasswordWrite = 'profile.password.write';

    /**
     * `GET /admins/me/preferences` (W2). Held by **both** roles: which language
     * one reads the interface in is not a privilege.
     */
    case ProfilePreferencesRead = 'profile.preferences.read';

    /** `PUT /admins/me/preferences` (W2). Held by both roles. */
    case ProfilePreferencesWrite = 'profile.preferences.write';

    // ------------------------------------------------------------------
    // A1 — domains. Superuser only for writes, as in webcyradm, where
    // `newdomain`, `editdomain` and `deletedomain` each began with a check on
    // `admintype`.
    // ------------------------------------------------------------------

    /** `GET /domains`, `GET /domains/{domain}`. Scoped for a domain master. */
    case DomainRead = 'domain.read';

    /** `POST /domains`, `PATCH /domains/{domain}` — including a rename. */
    case DomainWrite = 'domain.write';

    /** `DELETE /domains/{domain}`. */
    case DomainDelete = 'domain.delete';

    // ------------------------------------------------------------------
    // A2 — accounts.
    // ------------------------------------------------------------------

    /** `GET /domains/{domain}/accounts`, `GET /accounts/{id}`, `GET /accounts/{id}/quota`. */
    case AccountRead = 'account.read';

    /** `POST /domains/{domain}/accounts`, `PATCH /accounts/{id}`, `PUT /accounts/{id}/quota`. */
    case AccountWrite = 'account.write';

    /** `DELETE /accounts/{id}`. */
    case AccountDelete = 'account.delete';

    /**
     * `PUT /accounts/{id}/password`.
     *
     * Separate from {@see AccountWrite} because it is the one account change
     * that hands somebody access to a mailbox rather than describing it. A role
     * that may correct a display name need not be a role that may read the mail.
     */
    case AccountPasswordWrite = 'account.password.write';

    // ------------------------------------------------------------------
    // A3 — everything that decides where mail goes: aliases, catchall,
    // forwards, and the vacation notice.
    // ------------------------------------------------------------------

    /** Aliases, catchall, forwards and the vacation notice, read. */
    case AddressRead = 'address.read';

    /** The same four, created or changed. */
    case AddressWrite = 'address.write';

    /** The same four, removed. */
    case AddressDelete = 'address.delete';

    // ------------------------------------------------------------------
    // A4 — administering other administrators. Superuser only (E-18, and
    // webcyradm's `adminuser` screen).
    // ------------------------------------------------------------------

    /** `GET /admins`, `GET /admins/{username}`. */
    case AdminRead = 'admin.read';

    /** `POST /admins`, `PATCH /admins/{username}` — including the domain assignment. */
    case AdminWrite = 'admin.write';

    /** `DELETE /admins/{username}`. */
    case AdminDelete = 'admin.delete';

    // ------------------------------------------------------------------
    // A5 — mailing lists. Salt reads `lists`; webcyradm never wrote it, so
    // there is no legacy behaviour to preserve and deny-by-default applies.
    // ------------------------------------------------------------------

    /** `GET /lists`, `GET /lists/{id}`. */
    case ListRead = 'list.read';

    /** `POST /lists`, `PATCH /lists/{id}`. */
    case ListWrite = 'list.write';

    /** `DELETE /lists/{id}`. */
    case ListDelete = 'list.delete';

    // ------------------------------------------------------------------
    // S4 — the audit trail.
    // ------------------------------------------------------------------

    /** Reading `mailadmin_audit`. */
    case AuditRead = 'audit.read';

    // ------------------------------------------------------------------
    // A6 — operations.
    // ------------------------------------------------------------------

    /** `GET /maintenance/reconciliation` and the repair queue, read. */
    case MaintenanceRead = 'maintenance.read';

    /** `POST /maintenance/password-resets`, retrying a repair-queue entry. */
    case MaintenanceWrite = 'maintenance.write';

    /**
     * The declaration that no permission is required, rather than a permission.
     */
    public function isPublic(): bool
    {
        return $this === self::PublicAccess;
    }

    /**
     * Can a role hold this? Everything except {@see PublicAccess}.
     */
    public function isGrantable(): bool
    {
        return !$this->isPublic();
    }

    /**
     * The family this permission belongs to — the part before the first dot.
     */
    public function resource(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * Every permission a role can actually hold, in declaration order.
     *
     * @return list<self>
     */
    public static function grantable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => $p->isGrantable()));
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Model\DomainName;

/**
 * What may be done to a domain — A1's gate (architecture 6).
 *
 * Reading is scoped, writing is superuser-only. The asymmetry is deliberate and
 * it is webcyradm's: creating, renaming and deleting a domain each reach past
 * the database. A rename walks `renamemailbox` over every account in Cyrus
 * before the database is touched at all (CLAUDE.md, order of operations), and a
 * delete removes mail. Neither is an operation whose blast radius stops at the
 * domain master's own domain.
 *
 * ## Listing is not the same question
 *
 * {@see requireList()} checks the permission and nothing else, because there is
 * no single domain to check. **The narrowing is the query's job**, not this
 * class's — `Repository::scopedTo()` builds the predicate from the same actor. A
 * list endpoint that called `requireRead()` per row would answer `404` for a
 * domain master's own list, and one that called nothing would answer with
 * everybody's domains.
 */
final class DomainPolicy
{
    private function __construct()
    {
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireRead(ActorContext $actor, DomainName $domain): void
    {
        Guard::permission($actor, Permission::DomainRead);
        Guard::visible($actor, $domain);
    }

    /**
     * @throws AccessDenied
     */
    public static function requireList(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::DomainRead);
    }

    /**
     * No domain to check against: the domain does not exist yet, and a role
     * that may create one may create it anywhere.
     *
     * @throws AccessDenied
     */
    public static function requireCreate(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::DomainWrite);
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireWrite(ActorContext $actor, DomainName $domain): void
    {
        Guard::permission($actor, Permission::DomainWrite);
        Guard::visible($actor, $domain);
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireDelete(ActorContext $actor, DomainName $domain): void
    {
        Guard::permission($actor, Permission::DomainDelete);
        Guard::visible($actor, $domain);
    }

    public static function mayRead(ActorContext $actor, DomainName $domain): bool
    {
        return Guard::allows($actor, Permission::DomainRead, $domain);
    }

    public static function mayWrite(ActorContext $actor, DomainName $domain): bool
    {
        return Guard::allows($actor, Permission::DomainWrite, $domain);
    }

    public static function mayDelete(ActorContext $actor, DomainName $domain): bool
    {
        return Guard::allows($actor, Permission::DomainDelete, $domain);
    }
}

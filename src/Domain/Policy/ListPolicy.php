<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * Mailing lists — A5's gate.
 *
 * Superuser only, for now, and the reason is that there is nothing to preserve:
 * webcyradm had no list management at all. `lists` is written by Salt and read
 * by it; the table has four columns and no owner among them. Where there is no
 * legacy behaviour, deny-by-default decides, and widening later takes nothing
 * away from anybody while narrowing later would.
 *
 * ## What A5 needs if it wants to widen
 *
 * `lists.address` carries a domain, so a domain master *can* be scoped — the
 * hook is {@see requireVisible()}, which is written and tested and simply not
 * called by the `require*` methods below. Widening is then two changes: add the
 * three permissions to `RolePermissions::domainMaster()`, and call
 * {@see requireVisible()} from each method here. Neither touches a handler.
 */
final class ListPolicy
{
    private function __construct()
    {
    }

    /**
     * @throws AccessDenied
     */
    public static function requireRead(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::ListRead);
    }

    /**
     * @throws AccessDenied
     */
    public static function requireWrite(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::ListWrite);
    }

    /**
     * @throws AccessDenied
     */
    public static function requireDelete(ActorContext $actor): void
    {
        Guard::permission($actor, Permission::ListDelete);
    }

    /**
     * The scoping A5 would need to grant a domain master anything here.
     *
     * Not called from the methods above on purpose: calling it while no role
     * below superuser holds a `list.*` permission would be dead code that reads
     * like enforcement.
     *
     * @throws ResourceNotVisible
     */
    public static function requireVisible(ActorContext $actor, EmailAddress $listAddress): void
    {
        Guard::visibleObject($actor, 'mailing list', $listAddress->domain);
    }

    public static function mayManage(ActorContext $actor): bool
    {
        return Guard::allows($actor, Permission::ListWrite);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * Mailing lists — A5's gate.
 *
 * Superusers and domain masters (spec 4.3, "Rechte"). `lists` has no domain
 * column, so a domain master is scoped through the domain of the list
 * address — the one part of a list that names a domain.
 *
 * Two kinds of refusal, and the difference is the usual one (U-44):
 *
 *  - an **existing** list outside the actor's domains is not there for them:
 *    {@see requireVisible()} answers `404`, the same answer as for a list that
 *    does not exist at all;
 *  - **creating** a list in a domain outside the actor's is a refusal of the
 *    operation, not of an object — there is no object yet that a `404` could
 *    hide — so {@see requireCreateIn()} answers `403`.
 *
 * The `require*` methods without an address check the permission only; the use
 * cases call {@see requireVisible()} once they have loaded the list, and the
 * listing is narrowed by the repository (U-45).
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
     * A new list at this address: the permission, and the address's domain
     * inside the actor's scope — refused with `403`, see the class comment.
     *
     * @throws AccessDenied
     */
    public static function requireCreateIn(ActorContext $actor, EmailAddress $listAddress): void
    {
        Guard::permission($actor, Permission::ListWrite);

        if (!$actor->canSee($listAddress->domain)) {
            throw AccessDenied::outsideScope($actor, Permission::ListWrite, $listAddress->domain->toString());
        }
    }

    /**
     * An existing list is visible only within the actor's domains — `404`
     * otherwise.
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

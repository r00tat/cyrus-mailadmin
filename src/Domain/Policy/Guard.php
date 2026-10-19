<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Domain\Model\DomainName;

/**
 * The two questions every policy asks, written once.
 *
 *  1. **Does the role carry this permission?** If not, {@see AccessDenied} —
 *     `403`.
 *  2. **Is this object inside the actor's domain scope?** If not,
 *     {@see ResourceNotVisible} — `404`.
 *
 * **The order is not interchangeable.** Permission first: a role that may not
 * perform the operation at all learns nothing from being told so, whereas
 * checking visibility first would answer `404` for an object that exists and
 * `403` for one that does not — which is the leak the other way round.
 *
 * The policies in this namespace are named facades over these two calls. That
 * looks like indirection until one reads a handler: `AccountPolicy::requireDelete($actor, $account)`
 * says what is being decided, `Guard::permission(...)` twice in a row does not,
 * and the difference matters at the site where somebody later adds a rule.
 */
final class Guard
{
    private function __construct()
    {
    }

    /**
     * @throws AccessDenied
     */
    public static function permission(ActorContext $actor, Permission $permission): void
    {
        if (!RolePermissions::of($actor->role)->includes($permission)) {
            throw AccessDenied::permissionMissing($actor, $permission);
        }
    }

    /**
     * @throws ResourceNotVisible
     */
    public static function visible(ActorContext $actor, DomainName $domain): void
    {
        if (!$actor->canSee($domain)) {
            throw ResourceNotVisible::domain($actor, $domain);
        }
    }

    /**
     * As {@see visible()}, for an object that merely lives in the domain.
     *
     * The separate call exists so that the log line says "an account in
     * example.test" rather than "the domain example.test", which is the
     * difference between a reader understanding what happened and guessing.
     *
     * @throws ResourceNotVisible
     */
    public static function visibleObject(ActorContext $actor, string $what, DomainName $domain): void
    {
        if (!$actor->canSee($domain)) {
            throw ResourceNotVisible::inDomain($actor, $what, $domain);
        }
    }

    /**
     * Both, in the order above — the shape almost every policy method has.
     *
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function permissionInDomain(
        ActorContext $actor,
        Permission $permission,
        string $what,
        DomainName $domain,
    ): void {
        self::permission($actor, $permission);
        self::visibleObject($actor, $what, $domain);
    }

    /**
     * Whether both would pass, without throwing.
     *
     * For rendering, not for deciding: a caller that acts on the answer should
     * use the `require*` form, so that a refusal cannot be forgotten by writing
     * `if` and no `else`.
     */
    public static function allows(ActorContext $actor, Permission $permission, ?DomainName $domain = null): bool
    {
        if (!RolePermissions::of($actor->role)->includes($permission)) {
            return false;
        }

        return $domain === null || $actor->canSee($domain);
    }
}

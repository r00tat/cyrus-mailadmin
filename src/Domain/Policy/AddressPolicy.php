<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * Aliases, catchall, forwards and the vacation notice — A3's gate.
 *
 * One family for all four because they answer one question: where does mail for
 * this address go. Three of them are rows in `virtual` (architecture 3.4), the
 * fourth is a Sieve script, and a role that may redirect an address by writing a
 * row is not meaningfully restrained by being unable to redirect it by writing a
 * script.
 *
 * ## The destination is checked separately, and this is the interesting part
 *
 * An alias has two ends. The **address** is in the actor's domain — that is what
 * {@see requireWrite()} checks. The **destination** need not be: forwarding to
 * an outside address is the ordinary case and must keep working.
 *
 * But a destination inside another *local* domain is a different thing. A domain
 * master who may write in `a.test` and creates `anything@a.test → boss@b.test`
 * has not read anything they may not read — mail addressed to their own domain
 * goes where they said. A domain master who creates the alias the other way
 * round, in `b.test`, is the case that matters, and {@see requireWrite()}
 * already refuses it because the address is not theirs.
 *
 * {@see requireDestinationVisible()} exists for the rule A3 may decide it wants
 * on top: that a destination in a local domain must also be one the actor can
 * see. It is **not** called by default, because the default would break every
 * cross-departmental forward in an existing installation, and this package does
 * not get to make that call for A3 (A3, point 6).
 */
final class AddressPolicy
{
    private function __construct()
    {
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireRead(ActorContext $actor, EmailAddress $address): void
    {
        Guard::permissionInDomain($actor, Permission::AddressRead, 'address', $address->domain);
    }

    /**
     * Listing everything in one domain — aliases, catchall, the lot.
     *
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireListIn(ActorContext $actor, DomainName $domain): void
    {
        Guard::permissionInDomain($actor, Permission::AddressRead, 'address', $domain);
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireWrite(ActorContext $actor, EmailAddress $address): void
    {
        Guard::permissionInDomain($actor, Permission::AddressWrite, 'address', $address->domain);
    }

    /**
     * The catchall of a domain — `alias = '@domain'`, so there is no local part
     * to build an address from.
     *
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireCatchallWrite(ActorContext $actor, DomainName $domain): void
    {
        Guard::permissionInDomain($actor, Permission::AddressWrite, 'catchall', $domain);
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireDelete(ActorContext $actor, EmailAddress $address): void
    {
        Guard::permissionInDomain($actor, Permission::AddressDelete, 'address', $address->domain);
    }

    /**
     * Removing a single row of a domain's `virtual` entries — whatever its
     * type, including one that fits no pattern and therefore has no address to
     * check (A3, U-103).
     *
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireDeleteIn(ActorContext $actor, DomainName $domain): void
    {
        Guard::permissionInDomain($actor, Permission::AddressDelete, 'address', $domain);
    }

    /**
     * Optional: refuse a destination in a local domain the actor cannot see.
     *
     * `$isLocalDomain` is answered by the caller, because only A3 has the
     * `domain` table in front of it — this package does not read anything. A
     * destination outside every local domain is left alone: it is an ordinary
     * forward to the outside world and none of our business.
     *
     * @throws ResourceNotVisible
     */
    public static function requireDestinationVisible(
        ActorContext $actor,
        EmailAddress $destination,
        bool $isLocalDomain,
    ): void {
        if (!$isLocalDomain) {
            return;
        }

        Guard::visibleObject($actor, 'forwarding destination', $destination->domain);
    }

    public static function mayWrite(ActorContext $actor, EmailAddress $address): bool
    {
        return Guard::allows($actor, Permission::AddressWrite, $address->domain);
    }

    public static function mayDelete(ActorContext $actor, EmailAddress $address): bool
    {
        return Guard::allows($actor, Permission::AddressDelete, $address->domain);
    }
}

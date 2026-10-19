<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * What may be done to a mail account — A2's gate (architecture 6).
 *
 * A domain master holds all four account permissions; every one of them is
 * narrowed here to the domains they were assigned. That is the split webcyradm
 * had, and it is the split that matters: the account operations are the daily
 * work, and a domain master who could not perform them would be a role with
 * nothing to do.
 *
 * ## The domain comes from the identity, never from the request
 *
 * Each method takes a {@see MailboxIdentity}, whose `domain` is the value
 * `accountuser.domain_name` holds — the only place that records where the local
 * part ends (architecture 3.3). A handler that took `{domain}` out of the path
 * and checked *that* would be checking a claim the caller made, and an account
 * whose stored domain differs from the path segment would pass.
 *
 * ## Password setting asks a second time
 *
 * {@see requirePasswordChange()} is not {@see requireWrite()}. Setting a
 * mailbox password hands somebody access to the mail in it, which is a
 * different thing from correcting a name, and E-7 makes it the only way a user
 * ever gets a new password — there is no self-service. Keeping it a permission
 * of its own means a future read-mostly role is one line away.
 */
final class AccountPolicy
{
    private function __construct()
    {
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireRead(ActorContext $actor, MailboxIdentity $account): void
    {
        Guard::permissionInDomain($actor, Permission::AccountRead, 'account', $account->domain);
    }

    /**
     * Listing the accounts of one domain: the domain has to be visible, the
     * accounts in it then are.
     *
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireListIn(ActorContext $actor, DomainName $domain): void
    {
        Guard::permissionInDomain($actor, Permission::AccountRead, 'account', $domain);
    }

    /**
     * The account does not exist yet, so the domain it is to be created in is
     * what decides.
     *
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireCreateIn(ActorContext $actor, DomainName $domain): void
    {
        Guard::permissionInDomain($actor, Permission::AccountWrite, 'account', $domain);
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireWrite(ActorContext $actor, MailboxIdentity $account): void
    {
        Guard::permissionInDomain($actor, Permission::AccountWrite, 'account', $account->domain);
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requireDelete(ActorContext $actor, MailboxIdentity $account): void
    {
        Guard::permissionInDomain($actor, Permission::AccountDelete, 'account', $account->domain);
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible
     */
    public static function requirePasswordChange(ActorContext $actor, MailboxIdentity $account): void
    {
        Guard::permissionInDomain($actor, Permission::AccountPasswordWrite, 'account', $account->domain);
    }

    public static function mayRead(ActorContext $actor, MailboxIdentity $account): bool
    {
        return Guard::allows($actor, Permission::AccountRead, $account->domain);
    }

    public static function mayWrite(ActorContext $actor, MailboxIdentity $account): bool
    {
        return Guard::allows($actor, Permission::AccountWrite, $account->domain);
    }

    public static function mayDelete(ActorContext $actor, MailboxIdentity $account): bool
    {
        return Guard::allows($actor, Permission::AccountDelete, $account->domain);
    }

    public static function mayChangePassword(ActorContext $actor, MailboxIdentity $account): bool
    {
        return Guard::allows($actor, Permission::AccountPasswordWrite, $account->domain);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Exception\DomainErrorCode;

/**
 * The actor's role does not carry this permission — `403`.
 *
 * The distinction from {@see ResourceNotVisible} is the whole of S3's outward
 * behaviour, so it is worth stating plainly:
 *
 *  - **`403` is about the operation.** "Domain masters do not delete domains."
 *    It says nothing about any particular domain, so saying it costs nothing.
 *  - **`404` is about the object.** "There is no such account" — and it is the
 *    same answer whether the account is absent or merely somebody else's
 *    (CLAUDE.md). Answering `403` there would confirm that the object exists,
 *    which is how one enumerates other people's data.
 *
 * The message names the actor and the permission because it is written for the
 * log. It never reaches a client: the response carries `request.forbidden`, the
 * status and the request id, and nothing else.
 */
final class AccessDenied extends PolicyRefusal
{
    private function __construct(
        public readonly string $username,
        public readonly Permission $permission,
        string $message,
    ) {
        parent::__construct(
            DomainErrorCode::REQUEST_FORBIDDEN,
            $message,
            ['permission' => $permission->value],
        );
    }

    public static function permissionMissing(ActorContext $actor, Permission $permission): self
    {
        return new self($actor->username, $permission, sprintf(
            'The role "%s" does not carry the permission "%s".',
            $actor->role->value,
            $permission->value,
        ));
    }

    /**
     * A permission held in general, refused on this occasion — the actor is not
     * the person the operation is about.
     *
     * Used for the self-service endpoints, where holding the permission means
     * "may do this to yourself" and nothing more.
     */
    public static function notTheSubject(ActorContext $actor, Permission $permission, string $subject): self
    {
        return new self($actor->username, $permission, sprintf(
            'The permission "%s" applies to one\'s own account only, and "%s" is not "%s".',
            $permission->value,
            self::excerpt($subject),
            self::excerpt($actor->username),
        ));
    }
}

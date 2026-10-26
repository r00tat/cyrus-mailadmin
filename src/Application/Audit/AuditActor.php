<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AdminRole;

/**
 * Who acted, as the trail records it.
 *
 * Almost always an {@see ActorContext} — every use case has one as its first
 * parameter, so the common case is one call. The two other constructors exist
 * because the two most interesting entries in the table are written when there
 * is no actor context to be had:
 *
 *  - {@see named()} for a sign-in attempt. The name is what was **sent**, which
 *    may be an account that does not exist; that is precisely what makes a burst
 *    of these rows readable.
 *  - {@see system()} for something the application did without a person behind
 *    it — a command-line run. Written as a name no administrator can have, so
 *    that it cannot be confused with one.
 *
 * The role is `null` for both, and the column stores an empty string. "No role"
 * and "some role" have to stay distinguishable: a row claiming an unauthenticated
 * attempt was made by a superuser would be worse than one that admits it does
 * not know.
 */
final readonly class AuditActor
{
    /**
     * Not a legal `adminuser.username` — the column is a name, and a name with
     * angle brackets cannot be signed in as.
     */
    public const string SYSTEM = '<system>';

    private function __construct(
        public string $username,
        public ?AdminRole $role,
    ) {
    }

    /**
     * A {@see ActorContext::system()} actor is recorded with its name and no
     * role, like {@see system()}: nobody signed in.
     */
    public static function of(ActorContext $actor): self
    {
        return new self($actor->username, $actor->isSystem() ? null : $actor->role);
    }

    /**
     * An authenticated administrator identified by something other than an
     * {@see ActorContext} — the claims of a token being revoked, say, where the
     * context has already served its purpose and the name and role are all that
     * is left.
     *
     * @throws InvalidAuditEntry when the name is empty
     */
    public static function withRole(string $username, AdminRole $role): self
    {
        $name = trim($username);

        if ($name === '') {
            throw InvalidAuditEntry::emptyActor();
        }

        return new self($name, $role);
    }

    /**
     * An unauthenticated caller, identified only by the name they offered.
     *
     * @throws InvalidAuditEntry when the name is empty
     */
    public static function named(string $username): self
    {
        $name = trim($username);

        if ($name === '') {
            throw InvalidAuditEntry::emptyActor();
        }

        return new self($name, null);
    }

    /**
     * The application itself, with nobody behind it.
     */
    public static function system(): self
    {
        return new self(self::SYSTEM, null);
    }

    /**
     * What goes into `actor_role`; the empty string means "not authenticated".
     */
    public function roleValue(): string
    {
        return $this->role?->value ?? '';
    }

    public function isAuthenticated(): bool
    {
        return $this->role !== null;
    }
}

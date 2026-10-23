<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Access;

use Mailadmin\Domain\Exception\InvalidActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;

/**
 * Who is acting: user name, role, visible domains (architecture 6).
 *
 * Every use case takes one as its **first parameter, not optional** — that is
 * the whole point. An authorisation check one can forget to write is an
 * authorisation check that will be forgotten.
 *
 * **This is the value object only.** Enforcement lives in S3's policy objects,
 * which decide what a role may do and answer `404` rather than `403` for a
 * foreign object, so that the existence of other people's data is not
 * confirmed. The two methods here, {@see canSee()} and {@see requiresDomainScope()},
 * are facts about the actor, not decisions about a request.
 */
final readonly class ActorContext
{
    private function __construct(
        public string $username,
        public AdminRole $role,
        public DomainScope $scope,
        private bool $system = false,
    ) {
    }

    /**
     * The application itself, run from the command line by an operator — a
     * maintenance command, not a person signed in.
     *
     * It holds a superuser's permissions, because whoever can run a command
     * on the server can change the database anyway. Its name has angle
     * brackets, which no administrator created in mailadmin can have (`AdminNewUsername`), and the
     * audit trail records it without a role ({@see isSystem()}).
     *
     * @throws InvalidActorContext when the label is empty
     */
    public static function system(string $label): self
    {
        $name = self::guardUsername($label);

        return new self('<system:' . $name . '>', AdminRole::Superuser, DomainScope::all(), true);
    }

    /** Is this {@see system()} rather than a signed-in administrator? */
    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * @throws InvalidActorContext
     */
    public static function superuser(string $username): self
    {
        return new self(self::guardUsername($username), AdminRole::Superuser, DomainScope::all());
    }

    /**
     * @throws InvalidActorContext
     */
    public static function domainMaster(string $username, DomainName ...$domains): self
    {
        $name = self::guardUsername($username);
        $scope = DomainScope::of(...$domains);

        if ($scope->isEmpty()) {
            throw InvalidActorContext::domainMasterWithoutDomains($name);
        }

        return new self($name, AdminRole::DomainMaster, $scope);
    }

    /**
     * For a role and scope that were read from the database.
     *
     * A superuser is unrestricted whatever `domainadmin` says — the legacy
     * schema expresses that with the wildcard row, and a superuser whose
     * wildcard row is missing is still a superuser.
     *
     * @throws InvalidActorContext
     */
    public static function of(string $username, AdminRole $role, DomainScope $scope): self
    {
        $name = self::guardUsername($username);

        if ($role->isSuperuser()) {
            return new self($name, $role, DomainScope::all());
        }

        if ($scope->isEmpty()) {
            throw InvalidActorContext::domainMasterWithoutDomains($name);
        }

        return new self($name, $role, $scope);
    }

    public function isSuperuser(): bool
    {
        return $this->role->isSuperuser();
    }

    /**
     * Is this domain within the actor's scope? A fact, not a permission — S3
     * decides what follows from it.
     */
    public function canSee(DomainName $domain): bool
    {
        return $this->scope->includes($domain);
    }

    /**
     * Does a query for this actor have to be narrowed to their domains?
     */
    public function requiresDomainScope(): bool
    {
        return !$this->scope->isUnrestricted();
    }

    /**
     * @return list<DomainName>
     */
    public function visibleDomains(): array
    {
        return $this->scope->domains();
    }

    /**
     * @throws InvalidActorContext
     */
    private static function guardUsername(string $username): string
    {
        $name = trim($username);

        if ($name === '') {
            throw InvalidActorContext::emptyUsername();
        }

        return $name;
    }
}

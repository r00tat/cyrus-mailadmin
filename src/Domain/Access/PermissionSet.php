<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Access;

/**
 * A set of {@see Permission}s — what a role holds, as a value.
 *
 * A set rather than a list: membership is the only question ever asked of it,
 * duplicates are meaningless, and an ordering would suggest a precedence that
 * does not exist.
 *
 * {@see Permission::PublicAccess} may not be a member. It is the declaration
 * that an operation needs no permission, not something anybody holds, and a set
 * that contained it would answer `true` for an operation that never asked.
 */
final readonly class PermissionSet
{
    /**
     * @param array<string, Permission> $permissions keyed by the backing value,
     *                                               so that membership is a hash lookup
     */
    private function __construct(private array $permissions)
    {
    }

    /**
     * @throws \LogicException if `public` is among them — a programming error,
     *                         not something a request can cause
     */
    public static function of(Permission ...$permissions): self
    {
        $set = [];

        foreach ($permissions as $permission) {
            if (!$permission->isGrantable()) {
                throw new \LogicException(sprintf(
                    'Permission "%s" is the absence of a permission and cannot be granted to a role.',
                    $permission->value,
                ));
            }

            $set[$permission->value] = $permission;
        }

        return new self($set);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Every permission a role can hold. Used for the superuser, who by
     * definition has no exceptions — writing the list out would mean a new
     * permission silently failing to reach them.
     */
    public static function everything(): self
    {
        return self::of(...Permission::grantable());
    }

    public function includes(Permission $permission): bool
    {
        return array_key_exists($permission->value, $this->permissions);
    }

    public function with(Permission ...$permissions): self
    {
        return self::of(...$this->all(), ...$permissions);
    }

    /**
     * @return list<Permission>
     */
    public function all(): array
    {
        return array_values($this->permissions);
    }

    /**
     * The permission strings, sorted — the shape `GET /auth/me` answers with.
     *
     * Sorted because it goes over the wire: an order that depended on how the
     * set happened to be built would make two identical answers compare unequal,
     * and a frontend that caches on the response would refetch for nothing.
     *
     * @return list<string>
     */
    public function toStrings(): array
    {
        $values = array_keys($this->permissions);
        sort($values);

        return $values;
    }

    public function count(): int
    {
        return count($this->permissions);
    }

    public function isEmpty(): bool
    {
        return $this->permissions === [];
    }
}

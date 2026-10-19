<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

/**
 * What happened, as `<object>.<verb>` — `domain.create`, `account.delete`,
 * `session.sign_in`.
 *
 * ## Why a validated string and not an enum
 *
 * An enum would be the obvious choice and is the wrong one here. It would be a
 * single file that A1 to A6, S2 and M3 all have to edit, which is exactly the
 * kind of shared file U-3 to U-6 spent four decisions removing. The form is
 * enforced instead, which is what a search over the trail depends on; the
 * vocabulary is left to the package that owns the object.
 *
 * The shape is the same one the error codes use (U-7) for the same reason: a
 * reader who knows `domain.not_found` can guess `domain.delete`.
 *
 * Sub-objects are allowed — `account.password.write` — because the permission
 * that guards an operation is spelled that way and an entry that cannot be
 * named after its permission is harder to line up with one.
 */
final readonly class AuditAction
{
    /** As much as the `action` column holds. */
    public const int MAX_LENGTH = 64;

    private const string PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidAuditEntry when the name is not `<object>.<verb>` or is too long
     */
    public static function of(string $action): self
    {
        $name = trim($action);

        if (preg_match(self::PATTERN, $name) !== 1) {
            throw InvalidAuditEntry::malformedAction($action);
        }

        if (strlen($name) > self::MAX_LENGTH) {
            throw InvalidAuditEntry::actionTooLong($action, self::MAX_LENGTH);
        }

        return new self($name);
    }

    /**
     * The part before the first dot — the object family the action belongs to.
     */
    public function object(): string
    {
        return explode('.', $this->value)[0];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

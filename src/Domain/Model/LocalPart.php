<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Exception\InvalidAddress;

/**
 * The part of a mail address in front of the `@`.
 *
 * **A local part may contain dots.** That single fact is why
 * `MailboxIdentity` exists: `greta.mustermann.example.at` cannot be taken apart
 * without knowing the domain. Nothing in this class ever tries.
 *
 * Two doors, same reasoning as `DomainName`: `fromString()` is strict for new
 * input, `fromStoredValue()` is tolerant for rows that already exist and only
 * marks them `isConventional() === false` (U-22). The legacy system validated
 * nothing and accepted addresses such as `f*o*o+b*a*r@…`; those rows stay
 * readable and stay visible, but no new address may look like that.
 */
final readonly class LocalPart
{
    /**
     * RFC 5321 caps a local part at 64 octets, and so do we for new addresses.
     */
    public const int MAX_LENGTH = 64;

    /**
     * A stored value may be longer than {@see MAX_LENGTH} — `accountuser.username`
     * is a `varchar(255)` that holds local part *and* domain.
     */
    public const int MAX_STORED_LENGTH = 255;

    /**
     * Postfix runs with `recipient_delimiter = +`.
     *
     * The model knows the delimiter so that `detail()` and `withoutDetail()`
     * can name it once. It never strips a detail on its own: which address a
     * message is *routed* to is Postfix's business, and a lookup that silently
     * dropped everything after a `+` would find the wrong row.
     */
    public const string RECIPIENT_DELIMITER = '+';

    private function __construct(
        private string $value,
        private bool $conventional,
    ) {
    }

    /**
     * The strict door: letters, digits, `.`, `-`, `_`, `+`; no leading,
     * trailing or repeated dot; at most 64 bytes.
     *
     * @throws InvalidAddress
     */
    public static function fromString(string $value): self
    {
        $localPart = self::guardUsable($value, self::MAX_LENGTH);

        if (!self::isConventionalValue($localPart)) {
            throw InvalidAddress::unconventionalLocalPart($localPart);
        }

        return new self($localPart, true);
    }

    /**
     * The tolerant door: for a value that is already in the database.
     *
     * @throws InvalidAddress
     */
    public static function fromStoredValue(string $value): self
    {
        $localPart = self::guardUsable($value, self::MAX_STORED_LENGTH);

        return new self(
            $localPart,
            strlen($localPart) <= self::MAX_LENGTH && self::isConventionalValue($localPart),
        );
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * False for a stored value we would not accept from a form (U-22).
     */
    public function isConventional(): bool
    {
        return $this->conventional;
    }

    public function containsDot(): bool
    {
        return str_contains($this->value, '.');
    }

    /**
     * The part behind the recipient delimiter, or `null` when there is none.
     */
    public function detail(): ?string
    {
        $position = strpos($this->value, self::RECIPIENT_DELIMITER);

        if ($position === false || $position === 0) {
            return null;
        }

        return substr($this->value, $position + 1);
    }

    /**
     * The addressable part without `+detail`.
     *
     * @throws InvalidAddress
     */
    public function withoutDetail(): self
    {
        $position = strpos($this->value, self::RECIPIENT_DELIMITER);

        if ($position === false || $position === 0) {
            return $this;
        }

        return self::fromStoredValue(substr($this->value, 0, $position));
    }

    public function toLowerCase(): self
    {
        $lower = strtolower($this->value);

        if ($lower === $this->value) {
            return $this;
        }

        return new self($lower, $this->conventional);
    }

    public function isLowerCase(): bool
    {
        return $this->value === strtolower($this->value);
    }

    public function equals(self $other): bool
    {
        return strtolower($this->value) === strtolower($other->value);
    }

    public function length(): int
    {
        return strlen($this->value);
    }

    /**
     * @throws InvalidAddress
     */
    private static function guardUsable(string $value, int $limit): string
    {
        $localPart = trim($value);

        if ($localPart === '') {
            throw InvalidAddress::emptyLocalPart();
        }

        if (strlen($localPart) > $limit) {
            throw InvalidAddress::localPartTooLong(strlen($localPart), $limit);
        }

        if (str_contains($localPart, '@')) {
            throw InvalidAddress::localPartHasForbiddenCharacter(
                $localPart,
                'it contains "@", which can only ever be the domain separator',
            );
        }

        if (str_contains($localPart, '/')) {
            throw InvalidAddress::localPartHasForbiddenCharacter(
                $localPart,
                'it contains "/", the Cyrus hierarchy separator',
            );
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/', $localPart) === 1) {
            throw InvalidAddress::localPartHasForbiddenCharacter(
                $localPart,
                'it contains whitespace or a control character',
            );
        }

        return $localPart;
    }

    private static function isConventionalValue(string $value): bool
    {
        $atom = '[A-Za-z0-9_+-]+';

        return preg_match('/^' . $atom . '(?:\.' . $atom . ')*$/', $value) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Exception\InvalidAddress;

/**
 * A mail domain, in the form it is stored in `domain.domain_name`.
 *
 * **Two ways in, on purpose.** `fromString()` is the strict door for anything a
 * user types: ASCII letter-digit-hyphen labels, at least two of them, no
 * trailing dot. `fromStoredValue()` is the tolerant door for rows that already
 * exist: it rejects only what makes a value unusable — empty, an `@`, a slash,
 * whitespace, control characters, or more than 255 bytes — and marks everything
 * else `isConventional() === false`. That is U-22 in one class: existing data is
 * carried and shown, never silently dropped and never silently repaired.
 *
 * **Case is preserved, comparison is not case sensitive.** Cyrus downcases
 * recipients (`lmtp_downcase_rcpt: yes`), but `accountuser.username` is a
 * `varchar binary` column — a lookup with a differently cased spelling misses
 * the row. So the value keeps the exact bytes it was given and `equals()`
 * compares case insensitively. New domains should be created lower case;
 * `toLowerCase()` produces that form, the model does not force it on data that
 * already exists.
 *
 * **Internationalised names are punycode.** The model accepts A-labels
 * (`xn--…`) and rejects non-ASCII input. Converting a U-label to punycode needs
 * `ext-intl`, which is not a dependency (E-12) — the conversion belongs to the
 * edge, not here.
 */
final readonly class DomainName
{
    /** `domain.domain_name` is a `varchar(255)`. */
    public const int MAX_LENGTH = 255;

    /** The prefix of an internationalised A-label. */
    public const string PUNYCODE_PREFIX = 'xn--';

    private function __construct(
        private string $value,
        private bool $conventional,
    ) {
    }

    /**
     * The strict door: for a name a human just typed.
     *
     * @throws InvalidAddress
     */
    public static function fromString(string $value): self
    {
        $name = self::guardUsable($value);

        if (!self::isConventionalName($name)) {
            throw InvalidAddress::unconventionalDomainName($name);
        }

        return new self($name, true);
    }

    /**
     * The tolerant door: for a value that is already in the database.
     *
     * @throws InvalidAddress
     */
    public static function fromStoredValue(string $value): self
    {
        $name = self::guardUsable($value);

        return new self($name, self::isConventionalName($name));
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

    public function isInternationalized(): bool
    {
        foreach ($this->labels() as $label) {
            if (str_starts_with(strtolower($label), self::PUNYCODE_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return explode('.', $this->value);
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
    private static function guardUsable(string $value): string
    {
        $name = trim($value);

        if ($name === '') {
            throw InvalidAddress::emptyDomainName();
        }

        if (strlen($name) > self::MAX_LENGTH) {
            throw InvalidAddress::domainNameTooLong(strlen($name), self::MAX_LENGTH);
        }

        if (str_contains($name, '@')) {
            throw InvalidAddress::domainNameHasForbiddenCharacter($name, 'it contains "@"');
        }

        if (str_contains($name, '/')) {
            throw InvalidAddress::domainNameHasForbiddenCharacter($name, 'it contains "/"');
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/', $name) === 1) {
            throw InvalidAddress::domainNameHasForbiddenCharacter(
                $name,
                'it contains whitespace or a control character',
            );
        }

        return $name;
    }

    private static function isConventionalName(string $name): bool
    {
        $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

        return preg_match('/^' . $label . '(?:\.' . $label . ')+$/', $name) === 1;
    }
}

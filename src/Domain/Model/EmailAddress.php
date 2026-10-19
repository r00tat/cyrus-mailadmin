<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Exception\InvalidAddress;

/**
 * A mail address, `local@domain` — the one spelling of an account that never
 * changes, whatever naming scheme its mailbox uses (architecture 3.3).
 *
 * **This class may split a string, and that is not a contradiction.** The rule
 * „the Cyrus user name is generated, never taken apart" is about the *user
 * name*, where a dot is ambiguous. In an address the `@` is unambiguous: a
 * local part must not contain one, so the last `@` is the separator. Never
 * point `parse()` at a Cyrus user name — in the `dotted` scheme it has no `@`,
 * and in `fqun` it looks exactly like an address but must still be resolved
 * against `accountuser.domain_name`.
 */
final readonly class EmailAddress
{
    public function __construct(
        public LocalPart $localPart,
        public DomainName $domain,
    ) {
    }

    /**
     * Strict: for an address a human typed.
     *
     * @throws InvalidAddress
     */
    public static function parse(string $address): self
    {
        [$localPart, $domain] = self::split($address);

        return new self(LocalPart::fromString($localPart), DomainName::fromString($domain));
    }

    /**
     * Tolerant: for a `virtual.alias` value that is already in the database.
     *
     * @throws InvalidAddress
     */
    public static function parseStored(string $address): self
    {
        [$localPart, $domain] = self::split($address);

        return new self(
            LocalPart::fromStoredValue($localPart),
            DomainName::fromStoredValue($domain),
        );
    }

    /**
     * Does this string look like an address at all? Cheap enough for a
     * classifier to ask before it tries to parse (architecture 3.4).
     */
    public static function looksLikeAddress(string $value): bool
    {
        $position = strrpos($value, '@');

        return $position !== false && $position > 0 && $position < strlen($value) - 1;
    }

    public function toString(): string
    {
        return $this->localPart->toString() . '@' . $this->domain->toString();
    }

    /**
     * The address without `+detail`.
     *
     * @throws InvalidAddress
     */
    public function baseAddress(): self
    {
        $base = $this->localPart->withoutDetail();

        if ($base === $this->localPart) {
            return $this;
        }

        return new self($base, $this->domain);
    }

    public function isConventional(): bool
    {
        return $this->localPart->isConventional() && $this->domain->isConventional();
    }

    public function toLowerCase(): self
    {
        return new self($this->localPart->toLowerCase(), $this->domain->toLowerCase());
    }

    public function equals(self $other): bool
    {
        return $this->localPart->equals($other->localPart)
            && $this->domain->equals($other->domain);
    }

    public function isInDomain(DomainName $domain): bool
    {
        return $this->domain->equals($domain);
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws InvalidAddress
     */
    private static function split(string $address): array
    {
        $value = trim($address);
        $position = strrpos($value, '@');

        if ($position === false || $position === 0 || $position === strlen($value) - 1) {
            throw InvalidAddress::notAnAddress($value);
        }

        return [substr($value, 0, $position), substr($value, $position + 1)];
    }
}

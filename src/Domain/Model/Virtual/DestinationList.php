<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * The targets in `virtual.dest`.
 *
 * The column is a `longtext` and holds a comma separated list. A destination is
 * either a Cyrus user name (delivery into a local mailbox) or a mail address
 * (delivery to the outside) — the model keeps the raw string and offers
 * {@see addresses()} for the ones that parse, because deciding which is which
 * needs the account table.
 *
 * **Assumption:** comma is the separator, surrounding whitespace is dropped,
 * order is preserved, duplicates are kept. A3 owns the remaining questions
 * (maximum number of targets, and whether Postfix accepts the spelling we write
 * back) — its open point 1.
 */
final readonly class DestinationList
{
    public const string SEPARATOR = ',';

    /**
     * @param list<string> $destinations
     */
    private function __construct(private array $destinations)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Tolerant: for a value that is already in the database.
     */
    public static function fromLegacyString(string $value): self
    {
        $parts = explode(self::SEPARATOR, $value);
        $destinations = [];

        foreach ($parts as $part) {
            $destination = trim($part);

            if ($destination !== '') {
                $destinations[] = $destination;
            }
        }

        return new self($destinations);
    }

    /**
     * Strict: at least one destination, none of them empty or with a comma.
     *
     * @throws InvalidAddress
     */
    public static function of(string ...$destinations): self
    {
        $clean = [];

        foreach ($destinations as $destination) {
            $value = trim($destination);

            if ($value === '') {
                throw InvalidAddress::unusableDestination($destination);
            }

            if (str_contains($value, self::SEPARATOR)) {
                throw InvalidAddress::unusableDestination($value);
            }

            if (preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
                throw InvalidAddress::unusableDestination($value);
            }

            $clean[] = $value;
        }

        if ($clean === []) {
            throw InvalidAddress::emptyDestinationList();
        }

        return new self($clean);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->destinations;
    }

    /**
     * The destinations that are mail addresses. A Cyrus user name in the
     * `dotted` scheme has no `@` and therefore does not appear here — that is
     * the point: it is a local delivery, not an external one.
     *
     * @return list<EmailAddress>
     */
    public function addresses(): array
    {
        $addresses = [];

        foreach ($this->destinations as $destination) {
            if (EmailAddress::looksLikeAddress($destination)) {
                $addresses[] = EmailAddress::parseStored($destination);
            }
        }

        return $addresses;
    }

    public function first(): ?string
    {
        return $this->destinations[0] ?? null;
    }

    public function contains(string $destination): bool
    {
        foreach ($this->destinations as $candidate) {
            if (strcasecmp($candidate, $destination) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->destinations === [];
    }

    public function count(): int
    {
        return count($this->destinations);
    }

    /**
     * The value for `virtual.dest`.
     */
    public function toLegacyString(): string
    {
        return implode(self::SEPARATOR, $this->destinations);
    }

    public function equals(self $other): bool
    {
        return $this->destinations === $other->destinations;
    }
}

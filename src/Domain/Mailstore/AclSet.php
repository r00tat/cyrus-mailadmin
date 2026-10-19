<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Mailstore;

use Mailadmin\Domain\Exception\InvalidAcl;

/**
 * Every subject that holds rights on one mailbox — what `GETACL` returns
 * (architecture 9.1).
 *
 * A subject is a Cyrus authentication identity: an account's user name, an
 * administrator, a group (`group:…`) or the special `anyone`. The set keeps
 * subjects in the order it received them, so a listing stays stable.
 */
final readonly class AclSet
{
    /** The Cyrus identity that stands for „everybody, including anonymous". */
    public const string ANYONE = 'anyone';

    /**
     * @param array<string, Acl> $entries subject => rights
     */
    private function __construct(private array $entries)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, Acl> $entries subject => rights
     *
     * @throws InvalidAcl
     */
    public static function fromArray(array $entries): self
    {
        $clean = [];

        foreach ($entries as $subject => $acl) {
            // A numeric subject arrives as an int key; PHP does that silently.
            $clean[self::guardSubject((string) $subject)] = $acl;
        }

        return new self($clean);
    }

    /**
     * @param array<string, string> $entries subject => rights string
     *
     * @throws InvalidAcl
     */
    public static function fromStrings(array $entries): self
    {
        $parsed = [];

        foreach ($entries as $subject => $rights) {
            $parsed[self::guardSubject((string) $subject)] = Acl::fromString($rights);
        }

        return new self($parsed);
    }

    public function for(string $subject): ?Acl
    {
        return $this->entries[trim($subject)] ?? null;
    }

    public function grants(string $subject, AclRight $right): bool
    {
        return $this->for($subject)?->has($right) ?? false;
    }

    /**
     * @throws InvalidAcl
     */
    public function with(string $subject, Acl $acl): self
    {
        $entries = $this->entries;
        $entries[self::guardSubject($subject)] = $acl;

        return new self($entries);
    }

    public function without(string $subject): self
    {
        $entries = $this->entries;
        unset($entries[trim($subject)]);

        return new self($entries);
    }

    /**
     * @return list<string>
     */
    public function subjects(): array
    {
        return array_keys($this->entries);
    }

    /**
     * @return array<string, Acl>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * @return array<string, string>
     */
    public function toStrings(): array
    {
        $strings = [];

        foreach ($this->entries as $subject => $acl) {
            $strings[$subject] = $acl->toString();
        }

        return $strings;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Is the mailbox readable by everyone? Worth showing, because it is the one
     * ACL mistake with a privacy consequence.
     */
    public function isWorldReadable(): bool
    {
        return $this->grants(self::ANYONE, AclRight::Read);
    }

    /**
     * @throws InvalidAcl
     */
    private static function guardSubject(string $subject): string
    {
        $identity = trim($subject);

        if ($identity === '') {
            throw InvalidAcl::emptySubject();
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/', $identity) === 1) {
            throw InvalidAcl::unusableSubject($identity);
        }

        return $identity;
    }
}

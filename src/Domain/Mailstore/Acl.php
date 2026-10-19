<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Mailstore;

use Mailadmin\Domain\Exception\InvalidAcl;

/**
 * The rights one subject holds on one mailbox (architecture 9.1).
 *
 * Always rendered in the canonical order of {@see AclRight}, duplicates
 * removed. Which set the administrator and which set the owner get is M2's
 * decision (its open point 2); this class only offers the two spellings that
 * the legacy client used, so that the choice is made in one place and not
 * spelled out as a literal at every call site.
 */
final readonly class Acl
{
    /**
     * @param list<AclRight> $rights in canonical order, without duplicates
     */
    private function __construct(private array $rights)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(AclRight ...$rights): self
    {
        return new self(self::canonical($rights));
    }

    /**
     * @throws InvalidAcl
     */
    public static function fromString(string $rights): self
    {
        $parsed = [];

        foreach (str_split($rights) as $letter) {
            if (trim($letter) === '') {
                continue;
            }

            $right = AclRight::tryFrom($letter);

            if ($right === null) {
                throw InvalidAcl::unknownRight($letter, $rights);
            }

            $parsed[] = $right;
        }

        return new self(self::canonical($parsed));
    }

    /**
     * Everything a mailbox owner or an administrator needs, on a server that
     * announces the split rights: `lrswipkxtecda`.
     */
    public static function fullControl(): self
    {
        return self::of(
            AclRight::Lookup,
            AclRight::Read,
            AclRight::Seen,
            AclRight::Write,
            AclRight::Insert,
            AclRight::Post,
            AclRight::CreateMailbox,
            AclRight::DeleteMailbox,
            AclRight::DeleteMessage,
            AclRight::Expunge,
            AclRight::LegacyCreate,
            AclRight::LegacyDelete,
            AclRight::Administer,
        );
    }

    /**
     * The shorter spelling for servers that only know the compound rights:
     * `lrswipcda`.
     */
    public static function legacyFullControl(): self
    {
        return self::of(
            AclRight::Lookup,
            AclRight::Read,
            AclRight::Seen,
            AclRight::Write,
            AclRight::Insert,
            AclRight::Post,
            AclRight::LegacyCreate,
            AclRight::LegacyDelete,
            AclRight::Administer,
        );
    }

    public static function readOnly(): self
    {
        return self::of(AclRight::Lookup, AclRight::Read, AclRight::Seen);
    }

    public function has(AclRight $right): bool
    {
        return in_array($right, $this->rights, true);
    }

    /**
     * Does this ACL cover everything `$other` grants?
     */
    public function includes(self $other): bool
    {
        foreach ($other->rights as $right) {
            if (!$this->has($right)) {
                return false;
            }
        }

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->rights === [];
    }

    /**
     * @return list<AclRight>
     */
    public function rights(): array
    {
        return $this->rights;
    }

    public function with(AclRight ...$rights): self
    {
        return new self(self::canonical([...$this->rights, ...$rights]));
    }

    public function without(AclRight ...$rights): self
    {
        $kept = [];

        foreach ($this->rights as $right) {
            if (!in_array($right, $rights, true)) {
                $kept[] = $right;
            }
        }

        return new self($kept);
    }

    public function toString(): string
    {
        $letters = '';

        foreach ($this->rights as $right) {
            $letters .= $right->value;
        }

        return $letters;
    }

    public function equals(self $other): bool
    {
        return $this->rights === $other->rights;
    }

    /**
     * @param array<array-key, AclRight> $rights
     *
     * @return list<AclRight>
     */
    private static function canonical(array $rights): array
    {
        $canonical = [];

        foreach (AclRight::cases() as $case) {
            if (in_array($case, $rights, true)) {
                $canonical[] = $case;
            }
        }

        return $canonical;
    }
}

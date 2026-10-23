<?php

declare(strict_types=1);

namespace Mailadmin\Domain\MailingList;

use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * One row of `lists`: `name`, `tag`, `address`, `members` (A5, E-4).
 *
 * ## What this object does not do
 *
 * It does not generate an aliases file, and it never will. The configuration
 * management reads the table and renders the file (E-4); this application writes
 * the database and nothing else. A change here therefore takes effect at the
 * next configuration run, not at save time — which is a fact the API states on
 * every write (U-59) rather than something a user is left to discover.
 *
 * ## `tag` is carried, not interpreted
 *
 * What the column means was never recorded and could not be established from the
 * schema (package document, open point 2). So it is a free-text label: stored,
 * shown, changed on request, and never used to decide anything (U-58). Inventing
 * a meaning for a column an external tool writes is how two tools come to
 * disagree about the same field.
 *
 * ## Two doors, as everywhere in this model
 *
 * {@see fromStoredValues()} accepts what is in the table; {@see create()} and
 * the `with*` methods apply the stricter rules that new input has to satisfy.
 * A row we would not have accepted stays readable and stays visible (U-22) —
 * {@see addressIsUsable()} and {@see MemberList::unusableMembers()} say so
 * rather than hiding it.
 */
final readonly class MailingList
{
    /** `lists.tag` and `lists.address` are both `varchar(255)`. */
    public const int MAX_TAG_LENGTH = 255;

    /** @see MAX_TAG_LENGTH */
    public const int MAX_ADDRESS_LENGTH = 255;

    private function __construct(
        public MailingListName $name,
        public string $tag,
        public string $address,
        public MemberList $members,
    ) {
    }

    /**
     * A row as the database has it. Nothing here is rejected for being
     * unconventional — see the class comment.
     */
    public static function fromStoredValues(
        string $name,
        string $tag,
        string $address,
        ?string $members,
    ): self {
        return new self(
            MailingListName::fromStoredValue($name),
            $tag,
            $address,
            MemberList::fromStoredValue($members),
        );
    }

    /**
     * A list that does not exist yet. Every value goes through the strict door,
     * and every member counts as new.
     *
     * @param list<string> $members
     *
     * @throws InvalidMailingList
     */
    public static function create(string $name, string $tag, string $address, array $members): self
    {
        return new self(
            MailingListName::fromString($name),
            self::guardTag($tag),
            self::guardAddress($address),
            MemberList::none()->withMembers($members),
        );
    }

    /**
     * A new display name, through the strict door — the name is free text and
     * not the identity, so changing it touches nothing but this column.
     *
     * @throws InvalidMailingList
     */
    public function withName(string $name): self
    {
        return new self(MailingListName::fromString($name), $this->tag, $this->address, $this->members);
    }

    /**
     * @throws InvalidMailingList
     */
    public function withTag(string $tag): self
    {
        return new self($this->name, self::guardTag($tag), $this->address, $this->members);
    }

    /**
     * @param list<string> $members
     *
     * @throws InvalidMailingList
     */
    public function withMembers(array $members): self
    {
        $changed = $this->members->withMembers($members);

        if ($changed === $this->members) {
            return $this;
        }

        return new self($this->name, $this->tag, $this->address, $changed);
    }

    /**
     * Is the stored address a mail address at all? `false` for a row this
     * application would not have written — reported, not corrected (U-22).
     */
    public function addressIsUsable(): bool
    {
        return $this->parsedAddress() !== null;
    }

    /**
     * The domain the list address lives in, or `null` when the address is not
     * one.
     *
     * The only reason this exists is scoping: `lists` has no domain column, so a
     * domain master could only ever be scoped through the address
     * ({@see \Mailadmin\Domain\Policy\ListPolicy}).
     */
    public function domain(): ?DomainName
    {
        return $this->parsedAddress()?->domain;
    }

    private function parsedAddress(): ?EmailAddress
    {
        try {
            return EmailAddress::parseStored($this->address);
        } catch (InvalidAddress) {
            return null;
        }
    }

    /**
     * @throws InvalidMailingList
     */
    private static function guardTag(string $tag): string
    {
        // Before trimming, deliberately: PHP's `trim()` strips `\0`, `\n`, `\r`
        // and `\t` along with spaces, so a check on the trimmed value would let
        // exactly the characters through that it had just hidden. A tag is a
        // label — a line break in one is not whitespace to be tidied away, it is
        // a value that should not have been sent.
        if (preg_match('/[\x00-\x1F\x7F]/', $tag) === 1) {
            throw InvalidMailingList::tagHasForbiddenCharacter($tag, 'it contains a control character');
        }

        $value = trim($tag);

        if (strlen($value) > self::MAX_TAG_LENGTH) {
            throw InvalidMailingList::tagTooLong(strlen($value), self::MAX_TAG_LENGTH);
        }

        return $value;
    }

    /**
     * @throws InvalidMailingList
     */
    private static function guardAddress(string $address): string
    {
        $value = trim($address);

        if (strlen($value) > self::MAX_ADDRESS_LENGTH) {
            throw InvalidMailingList::addressTooLong(strlen($value), self::MAX_ADDRESS_LENGTH);
        }

        try {
            // Strict: a list address this application hands out is one it would
            // also accept as an account address.
            return EmailAddress::parse($value)->toString();
        } catch (InvalidAddress) {
            throw InvalidMailingList::addressNotAnAddress($value);
        }
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\MailingList;

use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * The `lists.members` column, read as a list and written back without damage.
 *
 * ## Why this class holds the original text
 *
 * `lists` is not ours. It is written by an external tool today and read by the
 * configuration management (E-4, analysis 4.1 and 6), and the exact separator
 * the existing 22 rows use was never captured — the package document asks for it
 * to be checked against production before anything is written, and that check
 * cannot be made from here.
 *
 * So this class does not need the answer. It keeps the **original bytes** and
 * hands them back unchanged whenever the parsed member sequence has not changed
 * ({@see withMembers()} returns `$this`). Reading a row and saving it again is
 * therefore byte-for-byte identical whatever the separator was — including a
 * `NULL` that stays `NULL`. Only a list whose membership actually changed is
 * re-rendered, and then in the one form the delivery script reads: one member
 * per line (spec 4.3). The earlier comma rendering (U-56) is gone — the script
 * splits on line breaks, so a comma-joined value would be one invalid recipient.
 *
 * Parsing is deliberately wider than rendering. Comma, semicolon, line break and
 * `<br>` in every spelling are accepted as separators, and HTML entities left
 * behind by the old application are decoded first, because guessing wrong on the
 * way *in* would turn one member into several or several into one, and a list
 * that is misparsed on the way in is a list that is destroyed on the way out.
 *
 * ## What is refused
 *
 * Only members that are being **added**, and only those that are not a mail
 * address (U-57, see {@see InvalidMailingList::memberNotAnAddress()}) or would
 * not read back as exactly themselves — a `,`, `;` or `<br>` in a display name
 * is a separator on the way in and would split the member in two, and an entity
 * would be decoded into something else. Members that are already stored are
 * reported by {@see unusableMembers()} and never rejected (U-22).
 *
 * The column is read solely by the delivery script `maillist.py`; the aliases
 * file only ever held the pipe line to it, never the members.
 */
final readonly class MemberList
{
    /** What the members are joined with when the list is re-rendered (spec 4.3). */
    public const string SEPARATOR = "\n";

    /**
     * Every byte that is read as a separator on the way in.
     *
     * Wider than {@see SEPARATOR} on purpose — see the class comment. Applied
     * after the entities are decoded, so an encoded `&lt;br&gt;` separates too.
     */
    public const string PARSE_PATTERN = '/(?:<br\s*\/?>|[,;\r\n])+/i';

    /**
     * The ceiling a rendered list has to stay under.
     *
     * MySQL's `TEXT` holds 65 535 bytes and `LONGTEXT` far more; the package
     * document describes the column as a `text`, and the test baseline declares
     * `LONGTEXT`. The smaller of the two is the one that must not be exceeded,
     * because a value that does not fit is truncated silently in a non-strict
     * installation — which is how a list loses its last members without anybody
     * being told.
     */
    public const int MAX_RENDERED_LENGTH = 65535;

    /**
     * @param list<string> $members
     */
    private function __construct(
        private ?string $raw,
        private array $members,
    ) {
    }

    /**
     * A list read from the database. `null` and `''` are both "no members", and
     * the difference between them is preserved.
     */
    public static function fromStoredValue(?string $raw): self
    {
        return new self($raw, self::parse($raw ?? ''));
    }

    /**
     * The starting point for a list that does not exist yet.
     *
     * Its member set is empty, so {@see withMembers()} treats every entry as new
     * and validates all of them.
     */
    public static function none(): self
    {
        return new self('', []);
    }

    /**
     * @return list<string>
     */
    public function members(): array
    {
        return $this->members;
    }

    /**
     * Exactly what belongs in the column — `null` when the row had `null` and
     * nothing has changed since.
     */
    public function toStoredValue(): ?string
    {
        return $this->raw;
    }

    public function isEmpty(): bool
    {
        return $this->members === [];
    }

    public function count(): int
    {
        return count($this->members);
    }

    public function contains(string $member): bool
    {
        return in_array($member, $this->members, true);
    }

    /**
     * Members that are not mail addresses — shown, not removed (U-22).
     *
     * Whatever odd entries the existing rows hold (a local user name, a file
     * path, a pipe), an administrator has to be able to see them; what they may
     * not do is add one.
     *
     * @return list<string>
     */
    public function unusableMembers(): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (string $member): bool => !self::isUsableMember($member),
        ));
    }

    /**
     * The addresses mail is delivered to, in order, one per usable member.
     *
     * The address part of `Name <address>`, entries that are not addresses left
     * out, duplicates (compared case-insensitively, first spelling wins) left
     * out. Gives the same result as `normalize_recipients()` in `maillist.py` for
     * every case in `tests/Fixtures/MemberFormat`, which keep the two in step.
     *
     * @return list<string>
     */
    public function deliverableAddresses(): array
    {
        $addresses = [];
        $seen = [];

        foreach ($this->members as $member) {
            if (!self::isUsableMember($member)) {
                continue;
            }

            $address = self::addressPart($member);
            $key = strtolower($address);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Is the stored text already in the form this class would render?
     *
     * Only interesting for a test and for a diagnosis: an unchanged list is
     * written back verbatim whether or not this is true.
     */
    public function isCanonical(): bool
    {
        return $this->raw === implode(self::SEPARATOR, $this->members);
    }

    /**
     * The same list with a new membership.
     *
     * **Returns `$this` when nothing changed** — that is the whole reason the
     * original text is kept. Equality is over the parsed sequence, so re-sending
     * exactly what was read is a no-op down to the byte, whatever separator or
     * spacing the row happens to use.
     *
     * @param list<string> $requested
     *
     * @throws InvalidMailingList when an entry that is not already stored is not
     *                            a mail address, or the result would not fit
     */
    public function withMembers(array $requested): self
    {
        $clean = self::normalise($requested);

        if ($clean === $this->members) {
            return $this;
        }

        // Every entry, stored ones included: a changed list is rendered anew,
        // and an entity decoded on read can be text the column cannot hold.
        // Reported by position, never by value — the value is an address.
        foreach (array_values($requested) as $index => $value) {
            if (!Latin1Text::fits(trim($value))) {
                throw InvalidMailingList::memberOutsideLatin1($index);
            }
        }

        foreach ($clean as $member) {
            // Already in the list: left alone, whatever it is. Being added: has
            // to be an address, and has to read back as exactly itself — a `,`,
            // `;` or `<br>` (a display name such as "Doe, John") is a separator
            // on the way in, and an entity would be decoded into other text.
            if ($this->contains($member)) {
                continue;
            }

            if (!self::isUsableMember($member) || self::parse($member) !== [$member]) {
                throw InvalidMailingList::memberNotAnAddress($member);
            }
        }

        $rendered = implode(self::SEPARATOR, $clean);

        if (strlen($rendered) > self::MAX_RENDERED_LENGTH) {
            throw InvalidMailingList::memberListTooLong(strlen($rendered), self::MAX_RENDERED_LENGTH);
        }

        return new self($rendered, $clean);
    }

    /**
     * @return list<string>
     */
    private static function parse(string $raw): array
    {
        // Decode first, then split: the old application stored `&lt;` and `&gt;`
        // around addresses, and `&lt;br&gt;` has to separate like `<br>` does.
        // Decoded entities become UTF-8 while the column is latin1, so a
        // character outside latin1 would not survive a write-back; production
        // holds no entities at all.
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = preg_split(self::PARSE_PATTERN, $decoded);

        return $parts === false ? [] : self::normalise($parts);
    }

    /**
     * Trim every entry and drop the empty ones. Duplicates are **kept**: stored
     * data is never silently edited here, and the delivery script removes
     * duplicates itself ({@see deliverableAddresses()}).
     *
     * @param array<array-key, string> $values
     *
     * @return list<string>
     */
    private static function normalise(array $values): array
    {
        $members = [];

        foreach ($values as $value) {
            $member = trim($value);

            if ($member !== '') {
                $members[] = $member;
            }
        }

        return $members;
    }

    /**
     * `address` out of `Name <address>`; a bare address is returned as it is.
     */
    private static function addressPart(string $member): string
    {
        if (preg_match('/<([^<>]*)>\s*$/', $member, $match) === 1) {
            return trim($match[1]);
        }

        return $member;
    }

    private static function isUsableMember(string $member): bool
    {
        $address = self::addressPart($member);

        if (!EmailAddress::looksLikeAddress($address)) {
            return false;
        }

        try {
            // The tolerant parse: a member may be an address this application
            // would not hand out, as long as it is an address at all.
            EmailAddress::parseStored($address);
        } catch (InvalidAddress) {
            return false;
        }

        return true;
    }
}

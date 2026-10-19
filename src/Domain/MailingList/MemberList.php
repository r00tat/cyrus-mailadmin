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
 * re-rendered, and then in the one form that is certain to be right for an
 * aliases file: comma-separated (U-56).
 *
 * Parsing is deliberately wider than rendering. Comma, semicolon and line break
 * are all accepted as separators, because guessing wrong on the way *in* would
 * turn one member into several or several into one, and a list that is
 * misparsed on the way in is a list that is destroyed on the way out.
 *
 * ## What is refused
 *
 * Only members that are being **added**, and only because of what the column
 * feeds: an aliases file, where `|command` and `/path/to/file` are not
 * addresses but instructions. See
 * {@see InvalidMailingList::memberNotAnAddress()} and U-57. Members that are already
 * stored are reported by {@see unusableMembers()} and never rejected (U-22).
 */
final readonly class MemberList
{
    /** What the members are joined with when the list is re-rendered (U-56). */
    public const string SEPARATOR = ',';

    /**
     * Every byte that is read as a separator on the way in.
     *
     * Wider than {@see SEPARATOR} on purpose — see the class comment.
     */
    public const string PARSE_PATTERN = '/[,;\r\n]+/';

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
     * An aliases file may legitimately contain a local user name, a file path or
     * a pipe. Whichever of those the existing rows hold, an administrator has to
     * be able to see them; what they may not do is add one.
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

        foreach ($clean as $member) {
            // Already in the list: left alone, whatever it is. Being added: has
            // to be an address, because the column feeds an aliases file.
            if (!$this->contains($member) && !self::isUsableMember($member)) {
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
        $parts = preg_split(self::PARSE_PATTERN, $raw);

        return $parts === false ? [] : self::normalise($parts);
    }

    /**
     * Trim every entry and drop the empty ones. Duplicates are **kept**: two
     * identical entries in an aliases file are two deliveries, and silently
     * removing one would change behaviour the administrator did not ask to
     * change.
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

    private static function isUsableMember(string $member): bool
    {
        if (!EmailAddress::looksLikeAddress($member)) {
            return false;
        }

        try {
            // The tolerant parse: a member may be an address this application
            // would not hand out, as long as it is an address at all.
            EmailAddress::parseStored($member);
        } catch (InvalidAddress) {
            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * A mailing list value that cannot be written to `lists` (A5).
 *
 * Everything this class refuses is refused for **new or changed** input. A row
 * that is already in the table is never rejected: `lists` is written by an
 * external tool today (E-4, analysis 4.1), and a value we would not accept from
 * a form is still a value that has to be readable and visible (U-22). The
 * tolerant doors on {@see \Mailadmin\Domain\MailingList\MailingListName} and
 * {@see \Mailadmin\Domain\MailingList\MemberList} exist for exactly that.
 *
 * The one refusal that is a security measure rather than tidiness is
 * {@see memberNotAnAddress()} — see the note there, and U-57.
 */
final class InvalidMailingList extends DomainError
{
    public static function emptyName(): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_NAME,
            'A mailing list name must not be empty.',
        );
    }

    public static function nameTooLong(int $length, int $limit): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_NAME,
            sprintf('A mailing list name must not exceed %d characters, got %d.', $limit, $length),
            ['length' => $length, 'limit' => $limit],
        );
    }

    public static function nameHasForbiddenCharacter(string $name, string $reason): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_NAME,
            sprintf('Mailing list name "%s" is not usable: %s.', self::excerpt($name), $reason),
            ['name' => self::excerpt($name), 'reason' => $reason],
        );
    }

    /**
     * `lists` is latin1. A character it cannot hold would make MariaDB refuse
     * the write or store `?` — see {@see \Mailadmin\Domain\MailingList\MailingListName}.
     * The excerpt is left out: it is exactly the text that may not render.
     */
    public static function nameOutsideLatin1(string $name): self
    {
        return new self(
            DomainErrorCode::LIST_NAME_OUTSIDE_LATIN1,
            'A mailing list name may only use printable latin1 characters (ISO 8859-1); '
            . 'the column cannot store the others.',
            ['length' => strlen($name)],
        );
    }

    /** See {@see nameOutsideLatin1()}. */
    public static function tagOutsideLatin1(): self
    {
        return new self(
            DomainErrorCode::LIST_TAG_OUTSIDE_LATIN1,
            'A mailing list tag may only use latin1 characters (ISO 8859-1); the column cannot store the others.',
        );
    }

    public static function tagTooLong(int $length, int $limit): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_TAG,
            sprintf('A mailing list tag must not exceed %d characters, got %d.', $limit, $length),
            ['length' => $length, 'limit' => $limit],
        );
    }

    public static function tagHasForbiddenCharacter(string $tag, string $reason): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_TAG,
            sprintf('Mailing list tag "%s" is not usable: %s.', self::excerpt($tag), $reason),
            ['tag' => self::excerpt($tag), 'reason' => $reason],
        );
    }

    public static function addressTooLong(int $length, int $limit): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_ADDRESS,
            sprintf('A mailing list address must not exceed %d bytes, got %d.', $limit, $length),
            ['length' => $length, 'limit' => $limit],
        );
    }

    /**
     * The list address of a **new or changed** list is not a mail address.
     */
    public static function addressNotAnAddress(string $address): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_ADDRESS,
            sprintf('"%s" is not a usable mailing list address.', self::excerpt($address)),
            ['address' => self::excerpt($address)],
        );
    }

    /**
     * A member that is being **added** is not a member this list can store.
     *
     * It is either not a mail address, or it would not read back as exactly
     * itself (a separator or an entity in the display name). The column is read
     * by the delivery script, which treats every entry as a recipient, so
     * arbitrary text must not get in.
     *
     * Members that are **already stored** are never checked against this: the
     * table predates this application, and refusing to save a list because a row
     * somebody else wrote contains something unexpected would lose the rest of
     * the list. They are reported instead — see
     * {@see \Mailadmin\Domain\MailingList\MemberList::unusableMembers()}.
     */
    public static function memberNotAnAddress(string $member): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_MEMBER,
            sprintf(
                'Member "%s" is not a member this list can store. A new member has to be a mail '
                . 'address whose display name contains no separator (comma, semicolon, line break) '
                . 'and no HTML entity.',
                self::excerpt($member),
            ),
            ['member' => self::excerpt($member)],
        );
    }

    /**
     * A member entry the latin1 column cannot hold. Carries the entry's
     * position in the request and nothing of its text: the entry is an
     * address, and this message may end up in a log.
     */
    public static function memberOutsideLatin1(int $index): self
    {
        return new self(
            DomainErrorCode::LIST_MEMBER_OUTSIDE_LATIN1,
            sprintf(
                'Member entry %d may only use latin1 characters (ISO 8859-1); the column cannot store the others.',
                $index,
            ),
            ['index' => $index],
        );
    }

    public static function memberListTooLong(int $length, int $limit): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_MEMBER,
            sprintf(
                'The rendered member list is %d bytes and would not fit the column, which holds %d.',
                $length,
                $limit,
            ),
            ['length' => $length, 'limit' => $limit],
        );
    }
}

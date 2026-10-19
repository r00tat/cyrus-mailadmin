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
            sprintf('A mailing list name must not exceed %d bytes, got %d.', $limit, $length),
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

    public static function unconventionalName(string $name): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_NAME,
            sprintf(
                'Mailing list name "%s" uses characters that are not allowed for new lists. '
                . 'Allowed: letters, digits and . - _ +, starting and ending with a letter or digit.',
                self::excerpt($name),
            ),
            ['name' => self::excerpt($name)],
        );
    }

    public static function tagTooLong(int $length, int $limit): self
    {
        return new self(
            DomainErrorCode::LIST_INVALID_TAG,
            sprintf('A mailing list tag must not exceed %d bytes, got %d.', $limit, $length),
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
     * A member that is being **added** is not a mail address.
     *
     * This is the one rule in this class that is not about tidiness. `lists` is
     * read by the configuration management, which renders it into an aliases
     * file, and an aliases file gives `|command` and `/path/to/file` meanings
     * that a mail address does not have. A member field that accepted arbitrary
     * text would let anyone who may edit a list have the MTA run a command.
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
                'Member "%s" is not a mail address. A new member has to be one: the configuration '
                . 'management renders this column into an aliases file, where other shapes are '
                . 'commands and file names.',
                self::excerpt($member),
            ),
            ['member' => self::excerpt($member)],
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

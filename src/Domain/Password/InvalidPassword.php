<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

use Mailadmin\Domain\Exception\DomainError;

/**
 * A password that {@see PasswordPolicy} refuses.
 *
 * **Nothing here ever names the password.** The base class offers
 * {@see DomainError::excerpt()} for quoting the offending input, and every
 * other domain rule uses it — this one must not. U-28 is explicit that the log
 * masking processor cannot look inside a string, so a secret in an exception
 * message is a secret in the log file. What the messages carry instead:
 *
 *  - lengths, limits and the subject — numbers and enum names;
 *  - for {@see containsPersonalData()}, the *term that matched* (a local part
 *    or a domain name), never the password around it. That term is already
 *    known to whoever is setting the password.
 *
 * `PasswordPolicyTest::testNoMessageOrContextEverCarriesThePassword` holds this
 * for every constructor below.
 */
final class InvalidPassword extends DomainError
{
    public static function empty(): self
    {
        return new self(
            PasswordErrorCode::PASSWORD_EMPTY,
            'The password is empty.',
        );
    }

    public static function tooShort(int $length, PasswordSubject $subject): self
    {
        return new self(
            PasswordErrorCode::PASSWORD_TOO_SHORT,
            sprintf(
                'The password is %d characters long; %s requires at least %d.',
                $length,
                $subject->table(),
                $subject->minimumLength(),
            ),
            [
                'length' => $length,
                'minimum_length' => $subject->minimumLength(),
                'subject' => $subject->value,
            ],
        );
    }

    /**
     * Longer than the target format can carry — bcrypt's 72-byte input limit
     * for `accountuser`, or the absolute cap.
     *
     * The wording matters for the translated message: the password is
     * *rejected*, not shortened. bcrypt would ignore the excess silently and
     * the user would end up with a weaker secret than the one entered (U-21).
     */
    public static function tooLong(int $lengthInBytes, int $limitInBytes, PasswordSubject $subject): self
    {
        return new self(
            PasswordErrorCode::PASSWORD_TOO_LONG,
            sprintf(
                'The password is %d bytes long; %s accepts at most %d. It is rejected, not truncated.',
                $lengthInBytes,
                $subject->table(),
                $limitInBytes,
            ),
            [
                'length_bytes' => $lengthInBytes,
                'maximum_length_bytes' => $limitInBytes,
                'subject' => $subject->value,
            ],
        );
    }

    public static function tooCommon(): self
    {
        return new self(
            PasswordErrorCode::PASSWORD_TOO_COMMON,
            'The password is on the block list of common passwords.',
        );
    }

    /**
     * @param string $term the local part, user name or domain name that occurs
     *                     in the password — not the password
     */
    public static function containsPersonalData(string $term): self
    {
        return new self(
            PasswordErrorCode::PASSWORD_CONTAINS_PERSONAL_DATA,
            sprintf('The password contains "%s".', self::excerpt($term, 64)),
            ['term' => self::excerpt($term, 64)],
        );
    }

    public static function invalidCharacters(string $reason): self
    {
        return new self(
            PasswordErrorCode::PASSWORD_INVALID_CHARACTERS,
            sprintf('The password is not usable: %s.', $reason),
            ['reason' => $reason],
        );
    }
}

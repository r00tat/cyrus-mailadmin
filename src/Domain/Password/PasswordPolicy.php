<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

/**
 * The password rules of U-21, in one place (finding S-8).
 *
 * ```text
 * minimum length      12 characters (adminuser) / 10 (accountuser)
 * maximum length      72 bytes for accountuser — rejected, never truncated
 *                     4096 bytes for everything (assumption, see Password)
 * character classes   none required
 * expiry              none
 * block list          the common passwords in resources/common-passwords.txt
 * personal data       must not contain the user name or the domain name
 * online check        none — the application talks to no foreign system
 * ```
 *
 * No character-class requirement and no expiry is not laxity: NIST SP 800-63B
 * dropped both because they push people towards `Sommer2026!` and towards
 * writing passwords down. Length plus a block list catches more.
 *
 * **The policy never logs and never quotes the password.** It has no logger,
 * takes none, and every {@see InvalidPassword} it raises carries lengths and
 * limits only (U-28).
 */
final readonly class PasswordPolicy
{
    public function __construct(private CommonPasswordList $commonPasswords)
    {
    }

    /**
     * Validate and wrap. The call S2, A2 and A4 make.
     *
     * @throws InvalidPassword
     */
    public function accept(
        string $plaintext,
        PasswordSubject $subject,
        ?PersonalTerms $terms = null,
    ): Password {
        return Password::create($plaintext, $subject, $this, $terms);
    }

    /**
     * Validate without wrapping — for a "check as you type" endpoint.
     *
     * @throws InvalidPassword
     */
    public function assertAcceptable(
        string $plaintext,
        PasswordSubject $subject,
        ?PersonalTerms $terms = null,
    ): void {
        if ($plaintext === '') {
            throw InvalidPassword::empty();
        }

        // Before anything counts characters: an invalid byte sequence has no
        // character count, and the front end, the database and Cyrus would each
        // read it differently.
        if (preg_match('//u', $plaintext) !== 1) {
            throw InvalidPassword::invalidCharacters('it is not valid UTF-8');
        }

        // A NUL byte is the dangerous one. `crypt(3)` and `pam_mysql` work on C
        // strings and stop there, so the secret that actually protects the
        // mailbox would be the part in front of it — shorter than what was
        // typed and shorter than what this class measured.
        if (preg_match('/[\x00-\x1F\x7F]/', $plaintext) === 1) {
            throw InvalidPassword::invalidCharacters('it contains a control character');
        }

        $limit = $this->maximumLengthInBytes($subject);

        if (strlen($plaintext) > $limit) {
            throw InvalidPassword::tooLong(strlen($plaintext), $limit, $subject);
        }

        $length = self::characterLength($plaintext);

        if ($length < $subject->minimumLength()) {
            throw InvalidPassword::tooShort($length, $subject);
        }

        if ($this->commonPasswords->contains($plaintext)) {
            throw InvalidPassword::tooCommon();
        }

        $term = ($terms ?? PersonalTerms::none())->matchIn($plaintext);

        if ($term !== null) {
            throw InvalidPassword::containsPersonalData($term);
        }
    }

    /**
     * `true` when {@see assertAcceptable()} would not throw.
     *
     * A convenience for callers that want a boolean; anything that needs to
     * tell the user *why* has to catch {@see InvalidPassword}, because the
     * reason is the error code.
     */
    public function isAcceptable(
        string $plaintext,
        PasswordSubject $subject,
        ?PersonalTerms $terms = null,
    ): bool {
        try {
            $this->assertAcceptable($plaintext, $subject, $terms);
        } catch (InvalidPassword) {
            return false;
        }

        return true;
    }

    /**
     * The byte limit in force for a subject: the format's, or the absolute cap.
     */
    public function maximumLengthInBytes(PasswordSubject $subject): int
    {
        return min(
            $subject->maximumLengthInBytes() ?? Password::ABSOLUTE_LIMIT,
            Password::ABSOLUTE_LIMIT,
        );
    }

    /**
     * Length in characters, the way U-21 counts.
     *
     * PCRE with `/u` rather than `mb_strlen()`: `ext-mbstring` is not among the
     * dependencies in `composer.json` (E-12), and a length rule that silently
     * counts bytes on an installation without it would let a nine-character
     * password with three umlauts through.
     *
     * Falls back to the byte count for input that is not valid UTF-8;
     * {@see assertAcceptable()} rejects that case before ever asking.
     */
    public static function characterLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);

        if ($count === false) {
            return strlen($value);
        }

        return $count;
    }
}

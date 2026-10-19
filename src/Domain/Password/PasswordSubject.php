<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

use Mailadmin\Domain\Model\PasswordFormat;

/**
 * Whose password this is — and therefore how it must be hashed and how long it
 * must be.
 *
 * **This enum is the safeguard.** Nothing in S1 takes an algorithm as an
 * argument. The only way to produce a hash is to hand {@see PasswordHasher} a
 * {@see Password}, and a `Password` cannot exist without a subject. The subject
 * answers, by `match` over a closed set of two cases, which format is written:
 *
 * | Subject                | Table         | Target format | Why                                     |
 * |------------------------|---------------|---------------|-----------------------------------------|
 * | {@see self::AdminUser}   | `adminuser`   | Argon2id      | no external consumer                    |
 * | {@see self::AccountUser} | `accountuser` | bcrypt `$2y$` | `pam_mysql` verifies it with `crypt(3)` |
 *
 * **Never choose a format for `accountuser` that `crypt(3)` cannot read.**
 * Argon2id there breaks IMAP and SMTP AUTH for every end user. That is why
 * {@see requiresCryptCompatibility()} exists next to {@see targetFormat()}:
 * `PasswordHasher::hash()` checks the produced hash against both, and
 * `PasswordSubjectTest` checks the pair for every case. Changing the table is an
 * escalation case (`docs/04-detailkonzept.md`, section 5), not a code change.
 */
enum PasswordSubject: string
{
    /** A mailadmin administrator — `adminuser`. */
    case AdminUser = 'adminuser';

    /** A mailbox owner — `accountuser`, read by `pam_mysql` and Cyrus. */
    case AccountUser = 'accountuser';

    /**
     * The format a newly set password is written in.
     *
     * A closed `match` over two cases with two literal results. There is no
     * configuration input here on purpose: a deployment must not be able to
     * turn `accountuser` into Argon2id by setting an environment variable.
     */
    public function targetFormat(): PasswordFormat
    {
        return match ($this) {
            self::AdminUser => PasswordFormat::Argon2id,
            self::AccountUser => PasswordFormat::Bcrypt,
        };
    }

    /**
     * Does something outside mailadmin verify these hashes?
     *
     * True for `accountuser`: `pam_mysql` reads the column through the
     * `allusers` view and calls `crypt(3)` on it (`CLAUDE.md`, E-3). A format
     * `crypt(3)` does not understand means "authentication failed" for every
     * mail client, with no error anywhere in mailadmin.
     */
    public function requiresCryptCompatibility(): bool
    {
        return match ($this) {
            self::AdminUser => false,
            self::AccountUser => true,
        };
    }

    /**
     * Minimum length in characters, not bytes (U-21).
     *
     * Lower for `accountuser` because end users type this into mail clients on
     * phones; higher for `adminuser` because that account governs every domain.
     */
    public function minimumLength(): int
    {
        return match ($this) {
            self::AdminUser => 12,
            self::AccountUser => 10,
        };
    }

    /**
     * Maximum length in **bytes**, or `null` where only the absolute cap applies.
     *
     * bcrypt silently ignores everything past 72 bytes. A password longer than
     * that would be a weaker secret than the user believes, so U-21 rejects it
     * instead of truncating. The limit is tied to the format, not to taste —
     * hence it is derived from {@see targetFormat()} rather than written out.
     */
    public function maximumLengthInBytes(): ?int
    {
        return match ($this->targetFormat()) {
            PasswordFormat::Bcrypt => Password::BCRYPT_INPUT_LIMIT,
            default => null,
        };
    }

    /**
     * The table this subject's hash lives in — for messages and audit entries.
     */
    public function table(): string
    {
        return $this->value;
    }
}

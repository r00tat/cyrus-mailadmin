<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

/**
 * The hash format a stored password is written in.
 *
 * **This enum is the data type only.** Recognising a format from a hash,
 * verifying against it and rehashing belong to S1
 * (`Infrastructure/Security/PasswordHasher`); nothing here looks at a hash. The
 * type exists in F4 because U-22 asks for the format to be a *field* of the
 * account representation, filterable in the account list (architecture 5.5) —
 * that is how the legacy hashes stay visible instead of quietly ageing.
 *
 * The two target formats are decided and not negotiable (E-3, E-13):
 * `adminuser` gets Argon2id, `accountuser` gets bcrypt, because `pam_mysql`
 * verifies the latter with `crypt(3)`. Every other case is an existing hash
 * that stays valid — there is no migration campaign.
 */
enum PasswordFormat: string
{
    /** `$argon2id$…` — target format for `adminuser`. */
    case Argon2id = 'argon2id';

    /** `$2y$…` — target format for `accountuser`, readable by `crypt(3)`. */
    case Bcrypt = 'bcrypt';

    /** `$1$…` */
    case Md5Crypt = 'md5crypt';

    /** `$5$…` */
    case Sha256Crypt = 'sha256crypt';

    /** `$6$…` */
    case Sha512Crypt = 'sha512crypt';

    /** 13 characters, no prefix. Only the first eight characters count. */
    case DesCrypt = 'descrypt';

    /** 40 hex characters. */
    case Sha1Hex = 'sha1hex';

    /** 41 characters starting with `*` — MySQL's `PASSWORD()`. */
    case MysqlPassword = 'mysql_password';

    /** No hash at all. */
    case Plaintext = 'plaintext';

    /** Recognised as nothing known — shown, never silently treated as valid. */
    case Unknown = 'unknown';

    /**
     * Is this one of the two current target formats?
     */
    public function isCurrent(): bool
    {
        return $this === self::Argon2id || $this === self::Bcrypt;
    }

    /**
     * A format from the legacy data — the filter U-22 asks for.
     */
    public function isLegacy(): bool
    {
        return !$this->isCurrent();
    }

    /**
     * Can `crypt(3)`, and therefore `pam_mysql`, verify this format?
     *
     * The one question that must never be answered wrong for `accountuser`: a
     * format `crypt(3)` does not understand breaks IMAP and SMTP login.
     */
    public function isCryptCompatible(): bool
    {
        return match ($this) {
            self::Bcrypt,
            self::Md5Crypt,
            self::Sha256Crypt,
            self::Sha512Crypt,
            self::DesCrypt => true,
            default => false,
        };
    }

    /**
     * Formats whose weakness is a finding of its own (S-2): they must stay
     * visible in the account list even though they keep working.
     */
    public function isWeak(): bool
    {
        return match ($this) {
            self::DesCrypt,
            self::Sha1Hex,
            self::MysqlPassword,
            self::Plaintext,
            self::Md5Crypt => true,
            default => false,
        };
    }
}

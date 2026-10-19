<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

use Mailadmin\Domain\Model\PasswordFormat;

/**
 * Recognises the format of a stored hash — architecture 5.5, in code.
 *
 * Pure string inspection, hence in the domain layer: no configuration decides
 * this, only the bytes in the column. `PasswordHasher::verify()` dispatches on
 * the result, `needsRehash()` compares it with the target format, and U-22
 * shows it in the account list so the legacy hashes do not age unobserved
 * (finding S-2).
 *
 * ```text
 * $argon2id$…              Argon2id        target format for adminuser
 * $2y$ $2a$ $2b$ $2x$      Bcrypt          target format for accountuser
 * $1$…                     Md5Crypt
 * $5$…                     Sha256Crypt
 * $6$…                     Sha512Crypt
 * *[0-9A-F]{40}            MysqlPassword   MySQL PASSWORD(), 41 characters
 * [0-9a-f]{40}             Sha1Hex
 * [./0-9A-Za-z]{13}        DesCrypt        only the first eight characters count
 * $other$… or {OTHER}…     Unknown         a hash, but not one we can read
 * anything else, non-empty Plaintext
 * empty                    Unknown
 * ```
 *
 * **Three deliberate rulings, because the legacy data is ambiguous:**
 *
 *  1. **An empty column is `Unknown`, never `Plaintext`.** Otherwise an account
 *     whose password was never set would be unlocked by submitting an empty
 *     password. `Unknown` never verifies.
 *  2. **13 printable characters are read as DES-crypt, not as a cleartext
 *     password of 13 characters.** Both readings exist in a webcyradm database
 *     and only one can win. DES wins because it is the far more common one
 *     there — and because the other way round would let anyone who has read the
 *     `accountuser` table log in by typing the stored hash. The same reasoning
 *     applies to 40 hex characters versus a 40-character cleartext password.
 *  3. **Anything shaped like a hash but not on the list above is `Unknown`, not
 *     `Plaintext`.** `$argon2i$…`, `$y$…` (yescrypt) or `{SSHA}…` came from
 *     some other tool; calling them cleartext would compare a user's password
 *     against a hash string and calling them Argon2id would be a lie in the
 *     account list. `Unknown` never verifies and always reports
 *     {@see \Mailadmin\Infrastructure\Security\PasswordHasher::needsRehash()}.
 */
final class PasswordFormatDetector
{
    /** The prefix mailadmin writes for `adminuser`. */
    private const string ARGON2ID_PREFIX = '$argon2id$';

    /**
     * Every bcrypt revision `crypt(3)` reads. mailadmin writes `$2y$`;
     * `$2a$`/`$2b$`/`$2x$` turn up in data written by other tools.
     */
    private const array BCRYPT_PREFIXES = ['$2y$', '$2a$', '$2b$', '$2x$'];

    private function __construct()
    {
    }

    public static function detect(string $storedHash): PasswordFormat
    {
        if ($storedHash === '') {
            return PasswordFormat::Unknown;
        }

        if (str_starts_with($storedHash, self::ARGON2ID_PREFIX)) {
            return PasswordFormat::Argon2id;
        }

        foreach (self::BCRYPT_PREFIXES as $prefix) {
            if (str_starts_with($storedHash, $prefix)) {
                return PasswordFormat::Bcrypt;
            }
        }

        if (str_starts_with($storedHash, '$1$')) {
            return PasswordFormat::Md5Crypt;
        }

        if (str_starts_with($storedHash, '$5$')) {
            return PasswordFormat::Sha256Crypt;
        }

        if (str_starts_with($storedHash, '$6$')) {
            return PasswordFormat::Sha512Crypt;
        }

        // MySQL PASSWORD(): '*' plus 40 upper-case hex digits.
        if (preg_match('/^\*[0-9A-F]{40}$/', $storedHash) === 1) {
            return PasswordFormat::MysqlPassword;
        }

        // SHA1 as hex. webcyradm wrote it lower case; accept either spelling.
        if (preg_match('/^[0-9a-fA-F]{40}$/', $storedHash) === 1) {
            return PasswordFormat::Sha1Hex;
        }

        // Traditional DES crypt: two salt characters and eleven of output, all
        // from the crypt alphabet. See ruling 2 above.
        if (preg_match('#^[./0-9A-Za-z]{13}$#', $storedHash) === 1) {
            return PasswordFormat::DesCrypt;
        }

        // Shaped like a hash from some other tool — modular crypt format with an
        // identifier we do not implement, or an LDAP/Dovecot `{SCHEME}` prefix.
        // See ruling 3 above.
        if (preg_match('/^(\$[A-Za-z0-9-]+\$|\{[A-Za-z0-9-]+\})/', $storedHash) === 1) {
            return PasswordFormat::Unknown;
        }

        // Anything left that is not empty is what webcyradm stored when it
        // stored nothing at all: the password itself.
        return PasswordFormat::Plaintext;
    }
}

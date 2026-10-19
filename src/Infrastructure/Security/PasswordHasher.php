<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordFormatDetector;
use Mailadmin\Domain\Password\PasswordSubject;

/**
 * Verifies every hash format webcyradm left behind, and writes new ones in the
 * one format the consumer of that table can read (architecture 5.5).
 *
 * ## Verifying
 *
 * ```php
 * if ($hasher->verify($submitted, $row['password'])) { … }
 * ```
 *
 * `verify()` recognises the format from the hash itself and never needs to be
 * told. Everything in architecture 5.5 is covered — Argon2id, bcrypt,
 * DES-crypt, `$1$`/`$5$`/`$6$`, SHA1 hex, MySQL `PASSWORD()` and cleartext —
 * and **all of it stays valid**: there is no forced migration (E-13, E-18).
 * Every comparison goes through `hash_equals()`.
 *
 * MySQL's `PASSWORD()` is reproduced in PHP, not asked of the database. A round
 * trip would put a cleartext password into the query log of a server that is
 * shared with Postfix.
 *
 * ## The unknown user
 *
 * ```php
 * $row === null ? $hasher->verifyDummy($submitted, $subject) : $hasher->verify(…);
 * ```
 *
 * {@see verifyDummy()} always returns `false`, but only after doing the same
 * work a real verification would. Without it the response time says whether a
 * user name exists, and enumerating mailbox names is the first step of every
 * attack on a mail server.
 *
 * ## Writing
 *
 * ```php
 * $hash = $hasher->hash($policy->accept($plain, PasswordSubject::AccountUser, $terms));
 * ```
 *
 * **There is no algorithm argument, here or anywhere below.** The format comes
 * from the {@see PasswordSubject} inside the {@see Password}, by a `match` over
 * two cases. On top of that, {@see hash()} refuses to return a hash that is not
 * in the subject's target format, and for `accountuser` it additionally asks
 * `crypt(3)` to verify the result before handing it back — the same call
 * `pam_mysql` will make. A caller cannot pick the wrong scheme, and a future
 * edit that breaks the invariant fails at the first password set instead of at
 * the first user who cannot fetch mail.
 *
 * ## Rehashing
 *
 * `needsRehash()` is asked after a **successful** verification, never
 * unconditionally, and the result is written only then — that is the whole
 * migration path (E-13). It reports `true` for every legacy format and for a
 * current format at outdated cost parameters. For `accountuser` it also reports
 * `true` for an Argon2id hash, which is what stops one from surviving in a
 * column `pam_mysql` reads.
 *
 * ## In tests
 *
 * Instantiate the real thing with a cheap cost —
 * `new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 4))`. There is
 * deliberately no interface to mock: a double would be free to return a hash in
 * a format nothing here would accept, and the point of this class is that no
 * such hash exists.
 *
 * **Nothing in this class logs.** It takes no logger and holds no reference to
 * one; U-28 cannot mask what a string interpolation put into a message.
 */
final class PasswordHasher
{
    /**
     * Throw-away hashes for {@see verifyDummy()}, one per subject, built on
     * first use so that they carry the configured cost.
     *
     * @var array<string, string>
     */
    private array $dummyHashes = [];

    /**
     * A random secret nobody knows, so verifying against the dummy hash cannot
     * succeed even by accident.
     */
    private readonly string $dummySecret;

    public function __construct(private readonly PasswordHashingOptions $options)
    {
        $this->dummySecret = bin2hex(random_bytes(32));
    }

    /**
     * The format a stored hash is written in — the field U-22 shows in the
     * account list and lets the operator filter by.
     */
    public function detectFormat(string $storedHash): PasswordFormat
    {
        return PasswordFormatDetector::detect($storedHash);
    }

    /**
     * Does `$plaintext` match `$storedHash`, in whichever format that is?
     *
     * Returns `false` for a hash in no recognised format and for an empty
     * column — an account whose password was never set must not be unlocked by
     * submitting an empty string.
     */
    public function verify(string $plaintext, string $storedHash): bool
    {
        $format = PasswordFormatDetector::detect($storedHash);

        return match ($format) {
            // password_verify() compares in constant time and parses the cost
            // parameters out of the hash itself.
            PasswordFormat::Argon2id,
            PasswordFormat::Bcrypt => password_verify($plaintext, $storedHash),

            // crypt(3) with the stored hash as the salt: the classic idiom, and
            // exactly what pam_mysql does with the same column.
            PasswordFormat::DesCrypt,
            PasswordFormat::Md5Crypt,
            PasswordFormat::Sha256Crypt,
            PasswordFormat::Sha512Crypt => hash_equals($storedHash, crypt($plaintext, $storedHash)),

            // webcyradm wrote lower-case hex; compare case-insensitively by
            // normalising the stored side, not by a case-insensitive compare.
            PasswordFormat::Sha1Hex => hash_equals(strtolower($storedHash), sha1($plaintext)),

            PasswordFormat::MysqlPassword => hash_equals($storedHash, self::mysqlPassword($plaintext)),

            PasswordFormat::Plaintext => hash_equals($storedHash, $plaintext),

            // Empty, or a hash from a tool we do not implement. Burn the same
            // time as a real verification before saying no.
            PasswordFormat::Unknown => $this->verifyDummy($plaintext, PasswordSubject::AccountUser),
        };
    }

    /**
     * Always `false`, but at the cost of a real verification.
     *
     * Call this on the branch where the user name was not found. The subject
     * decides which format's cost is simulated: an administrator login should
     * cost an Argon2id verification, an account login a bcrypt one.
     */
    public function verifyDummy(string $plaintext, PasswordSubject $subject): bool
    {
        // The result is discarded on purpose: $plaintext cannot equal a 64-hex
        // random secret, and even if it did, saying "yes" to an unknown user
        // name would be the bug this method exists to avoid.
        password_verify($plaintext, $this->dummyHashFor($subject));

        return false;
    }

    /**
     * The hash to store, in the target format of the password's subject.
     *
     * @throws PasswordHashingFailed when the produced hash is not in the target
     *                               format, or when `crypt(3)` cannot verify a
     *                               hash destined for `accountuser`
     */
    public function hash(Password $password): string
    {
        $subject = $password->subject();
        $target = $subject->targetFormat();
        $plaintext = $password->reveal();

        $hash = match ($target) {
            PasswordFormat::Argon2id => $this->argon2id($plaintext, $subject),
            PasswordFormat::Bcrypt => password_hash($plaintext, PASSWORD_BCRYPT, $this->options->bcryptOptions()),
            // Unreachable: PasswordSubject::targetFormat() is a match over two
            // cases returning these two. Present so that adding a third case
            // fails loudly instead of falling through to a default.
            default => throw PasswordHashingFailed::unsupportedTargetFormat($subject, $target),
        };

        return $this->guard($hash, $plaintext, $subject, $target);
    }

    /**
     * Should this hash be replaced after a successful verification (E-13)?
     *
     * `true` for every legacy format, for the wrong target format, and for the
     * right format at parameters that no longer match the configuration.
     * `false` only for a hash that is already exactly what {@see hash()} would
     * produce today.
     */
    public function needsRehash(string $storedHash, PasswordSubject $subject): bool
    {
        $format = PasswordFormatDetector::detect($storedHash);

        if ($format !== $subject->targetFormat()) {
            return true;
        }

        return match ($format) {
            PasswordFormat::Argon2id => password_needs_rehash(
                $storedHash,
                PASSWORD_ARGON2ID,
                $this->options->argon2Options(),
            ),
            PasswordFormat::Bcrypt => password_needs_rehash(
                $storedHash,
                PASSWORD_BCRYPT,
                $this->options->bcryptOptions(),
            ),
            default => true,
        };
    }

    /**
     * MySQL's `PASSWORD()` for the 4.1 password format, in PHP.
     *
     * `'*' . upper(hex(sha1(sha1(password, raw))))`. The inner digest is taken
     * raw, not as hex — that is the detail that makes the reimplementation
     * either right or useless. Verified against the published vector for
     * `password`, which every MySQL 4.1+ server produces as
     * `*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19`.
     */
    public static function mysqlPassword(string $plaintext): string
    {
        return '*' . strtoupper(sha1(sha1($plaintext, true)));
    }

    /**
     * @throws PasswordHashingFailed
     */
    private function argon2id(string $plaintext, PasswordSubject $subject): string
    {
        if (!in_array('argon2id', password_algos(), true)) {
            throw PasswordHashingFailed::algorithmUnavailable('argon2id', $subject);
        }

        return password_hash($plaintext, PASSWORD_ARGON2ID, $this->options->argon2Options());
    }

    /**
     * The three checks that stand between a caller and a hash `pam_mysql`
     * cannot read.
     *
     * @throws PasswordHashingFailed
     */
    private function guard(
        string $hash,
        string $plaintext,
        PasswordSubject $subject,
        PasswordFormat $target,
    ): string {
        $produced = PasswordFormatDetector::detect($hash);

        if ($produced !== $target) {
            throw PasswordHashingFailed::wrongFormatProduced($subject, $target, $produced);
        }

        if (!$subject->requiresCryptCompatibility()) {
            return $hash;
        }

        if (!$produced->isCryptCompatible()) {
            throw PasswordHashingFailed::notCryptCompatible($subject, $produced);
        }

        // The proof rather than the promise: the same call pam_mysql makes,
        // against the hash we are about to hand back. It costs one more bcrypt
        // round, on an operation that happens when an administrator sets a
        // password — not on the login path.
        if (!hash_equals($hash, crypt($plaintext, $hash))) {
            throw PasswordHashingFailed::cryptRoundTripFailed($subject);
        }

        return $hash;
    }

    private function dummyHashFor(PasswordSubject $subject): string
    {
        $cached = $this->dummyHashes[$subject->value] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        $hash = match ($subject->targetFormat()) {
            PasswordFormat::Argon2id => $this->argon2id($this->dummySecret, $subject),
            default => password_hash($this->dummySecret, PASSWORD_BCRYPT, $this->options->bcryptOptions()),
        };

        $this->dummyHashes[$subject->value] = $hash;

        return $hash;
    }
}

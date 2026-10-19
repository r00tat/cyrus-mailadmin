<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\PasswordSubject;
use RuntimeException;

/**
 * The hasher produced something other than the target format — or could not
 * produce anything at all.
 *
 * Every one of these is a defect or a broken PHP build, never user input. They
 * abort the operation on purpose: writing a hash that `pam_mysql` cannot read
 * into `accountuser` would lock the user out of IMAP and SMTP with no error
 * anywhere, and it would only be noticed by the person who can no longer read
 * their mail.
 *
 * **No message here names a password or a hash** (U-28) — only subjects,
 * formats and algorithm names.
 */
final class PasswordHashingFailed extends RuntimeException
{
    public static function algorithmUnavailable(string $algorithm, PasswordSubject $subject): self
    {
        return new self(sprintf(
            'This PHP build offers no "%s"; it is the target format for %s. '
            . 'Rebuild PHP with the algorithm enabled — mailadmin will not fall back to another one.',
            $algorithm,
            $subject->table(),
        ));
    }

    /**
     * A subject whose target format the hasher has no branch for.
     *
     * Unreachable today — {@see PasswordSubject::targetFormat()} is a `match`
     * over two cases. It exists so that a third case added later stops here
     * instead of quietly taking a fallback.
     */
    public static function unsupportedTargetFormat(PasswordSubject $subject, PasswordFormat $target): self
    {
        return new self(sprintf(
            'No hashing branch for target format %s (subject %s). '
            . 'Adding a PasswordSubject means adding a branch, not a default.',
            $target->value,
            $subject->table(),
        ));
    }

    public static function wrongFormatProduced(
        PasswordSubject $subject,
        PasswordFormat $expected,
        PasswordFormat $produced,
    ): self {
        return new self(sprintf(
            'Hashing for %s produced %s where %s was required.',
            $subject->table(),
            $produced->value,
            $expected->value,
        ));
    }

    /**
     * The one that must never be reachable: a hash for `accountuser` that
     * `crypt(3)` — and therefore `pam_mysql` — cannot verify.
     */
    public static function notCryptCompatible(PasswordSubject $subject, PasswordFormat $produced): self
    {
        return new self(sprintf(
            'Hashing for %s produced %s, which crypt(3) cannot verify. pam_mysql reads this '
            . 'column, so storing it would break IMAP and SMTP AUTH for the user.',
            $subject->table(),
            $produced->value,
        ));
    }

    /**
     * The produced hash has the right shape but `crypt(3)` does not confirm it.
     */
    public static function cryptRoundTripFailed(PasswordSubject $subject): self
    {
        return new self(sprintf(
            'A hash for %s was produced in the target format, but crypt(3) did not verify the '
            . 'password against it. Nothing was stored.',
            $subject->table(),
        ));
    }
}

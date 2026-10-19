<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * A stored Cyrus user name and its `accountuser.domain_name` do not fit
 * together, or the stored name is unusable on its own.
 *
 * This is the failure mode that matters most in the whole model: the pair is
 * the *only* place that says where the local part ends (architecture 3.3). When
 * it contradicts itself there is nothing to fall back on — a guess would put a
 * mailbox under a name nobody can reach.
 */
final class InvalidMailboxIdentity extends DomainError
{
    public static function emptyUsername(): self
    {
        return new self(
            DomainErrorCode::ACCOUNT_INVALID_IDENTITY,
            'A stored Cyrus user name must not be empty.',
        );
    }

    public static function dottedUsernameDoesNotEndInDomain(string $username, string $domain): self
    {
        return new self(
            DomainErrorCode::ACCOUNT_IDENTITY_MISMATCH,
            sprintf(
                'Stored Cyrus user name "%s" does not end in ".%s", so the local part cannot be '
                . 'determined. The name and accountuser.domain_name contradict each other.',
                self::excerpt($username),
                self::excerpt($domain),
            ),
            ['username' => self::excerpt($username), 'domain' => self::excerpt($domain)],
        );
    }

    public static function fqunUsernameHasOtherDomain(string $username, string $domain): self
    {
        return new self(
            DomainErrorCode::ACCOUNT_IDENTITY_MISMATCH,
            sprintf(
                'Stored Cyrus user name "%s" carries a domain other than accountuser.domain_name '
                . '"%s".',
                self::excerpt($username),
                self::excerpt($domain),
            ),
            ['username' => self::excerpt($username), 'domain' => self::excerpt($domain)],
        );
    }

    public static function localPartWouldBeEmpty(string $username, string $domain): self
    {
        return new self(
            DomainErrorCode::ACCOUNT_IDENTITY_MISMATCH,
            sprintf(
                'Stored Cyrus user name "%s" consists of nothing but the domain "%s"; the local '
                . 'part would be empty.',
                self::excerpt($username),
                self::excerpt($domain),
            ),
            ['username' => self::excerpt($username), 'domain' => self::excerpt($domain)],
        );
    }
}

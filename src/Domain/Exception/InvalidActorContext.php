<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * An actor whose role and visible domains contradict each other.
 */
final class InvalidActorContext extends DomainError
{
    public static function emptyUsername(): self
    {
        return new self(
            DomainErrorCode::ADMIN_INVALID_SCOPE,
            'An actor needs a user name.',
        );
    }

    public static function domainMasterWithoutDomains(string $username): self
    {
        return new self(
            DomainErrorCode::ADMIN_INVALID_SCOPE,
            sprintf(
                'Domain master "%s" has no visible domain. An actor who may see nothing is a '
                . 'configuration error, not an empty result.',
                self::excerpt($username),
            ),
            ['username' => self::excerpt($username)],
        );
    }
}

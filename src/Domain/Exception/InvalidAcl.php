<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * An ACL string carries a right Cyrus does not know, or names no subject.
 */
final class InvalidAcl extends DomainError
{
    public static function unknownRight(string $right, string $rights): self
    {
        return new self(
            DomainErrorCode::MAILSTORE_INVALID_ACL,
            sprintf(
                'Unknown ACL right "%s" in "%s".',
                self::excerpt($right, 8),
                self::excerpt($rights),
            ),
            ['right' => self::excerpt($right, 8), 'rights' => self::excerpt($rights)],
        );
    }

    public static function emptySubject(): self
    {
        return new self(
            DomainErrorCode::MAILSTORE_INVALID_ACL,
            'An ACL subject must not be empty.',
        );
    }

    public static function unusableSubject(string $subject): self
    {
        return new self(
            DomainErrorCode::MAILSTORE_INVALID_ACL,
            sprintf('ACL subject "%s" is not usable.', self::excerpt($subject)),
            ['subject' => self::excerpt($subject)],
        );
    }
}

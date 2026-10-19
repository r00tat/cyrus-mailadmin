<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * A domain's settings — account limit, default folders — are not usable.
 */
final class InvalidDomainConfiguration extends DomainError
{
    public static function negativeAccountLimit(int $limit): self
    {
        return new self(
            DomainErrorCode::DOMAIN_INVALID_ACCOUNT_LIMIT,
            sprintf('An account limit must not be negative, got %d.', $limit),
            ['limit' => $limit],
        );
    }

    public static function emptyFolderName(): self
    {
        return new self(
            DomainErrorCode::DOMAIN_INVALID_FOLDER,
            'A default folder name must not be empty.',
        );
    }

    public static function unusableFolderName(string $folder, string $reason): self
    {
        return new self(
            DomainErrorCode::DOMAIN_INVALID_FOLDER,
            sprintf('Default folder "%s" is not usable: %s.', self::excerpt($folder), $reason),
            ['folder' => self::excerpt($folder), 'reason' => $reason],
        );
    }
}

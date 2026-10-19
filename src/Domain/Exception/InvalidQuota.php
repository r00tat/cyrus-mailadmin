<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * A quota value Cyrus could not carry.
 */
final class InvalidQuota extends DomainError
{
    public static function negativeLimit(int $kibibytes): self
    {
        return new self(
            DomainErrorCode::QUOTA_INVALID,
            sprintf('A quota limit must not be negative, got %d KiB.', $kibibytes),
            ['limit_kib' => $kibibytes],
        );
    }

    public static function negativeUsage(int $kibibytes): self
    {
        return new self(
            DomainErrorCode::QUOTA_INVALID,
            sprintf('A quota usage must not be negative, got %d KiB.', $kibibytes),
            ['used_kib' => $kibibytes],
        );
    }

    public static function unitOverflow(int $value, string $unit): self
    {
        return new self(
            DomainErrorCode::QUOTA_INVALID,
            sprintf('%d %s does not fit into a quota expressed in kibibytes.', $value, $unit),
            ['value' => $value, 'unit' => $unit],
        );
    }
}

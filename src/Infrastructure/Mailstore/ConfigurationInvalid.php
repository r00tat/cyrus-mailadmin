<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

/**
 * The mailstore configuration is incomplete, contradictory or unsafe.
 *
 * Raised at construction, not at connect time. A missing administrator password
 * or an unreadable CA bundle is a deployment fault, and the useful moment to say
 * so is the one where the operator is still looking — not three requests later
 * when a mailbox fails to appear.
 */
final class ConfigurationInvalid extends MailstoreError
{
    public static function missingHost(): self
    {
        return new self(ErrorCode::CONFIGURATION_INVALID, 'The mailstore host is empty.');
    }

    public static function portOutOfRange(int $port): self
    {
        return new self(
            ErrorCode::CONFIGURATION_INVALID,
            sprintf('Port %d is outside 1..65535.', $port),
        );
    }

    public static function timeoutTooSmall(string $which, float $seconds, float $minimum): self
    {
        return new self(
            ErrorCode::CONFIGURATION_INVALID,
            sprintf(
                'The %s timeout of %.1f s is below the %.1f s floor.',
                $which,
                $seconds,
                $minimum,
            ),
        );
    }

    public static function caFileUnreadable(string $path): self
    {
        return new self(
            ErrorCode::CONFIGURATION_INVALID,
            sprintf(
                'The CA bundle "%s" is not readable. Refusing to fall back to the system '
                . 'trust store, which would verify against the wrong anchor unnoticed.',
                $path,
            ),
        );
    }

    /**
     * The name of the variable, never its value — the value is the password.
     */
    public static function missingCredential(string $variable): self
    {
        return new self(
            ErrorCode::CONFIGURATION_INVALID,
            sprintf('%s is not set; the mailstore administrator has no credentials.', $variable),
        );
    }

    public static function unusableAdministrator(string $reason): self
    {
        return new self(
            ErrorCode::CONFIGURATION_INVALID,
            sprintf('The mailstore administrator name is unusable: %s', $reason),
        );
    }
}

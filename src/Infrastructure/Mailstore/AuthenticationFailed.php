<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

/**
 * The administrator could not log in, or the server refused proxy authorisation.
 *
 * **No factory here takes a password.** The messages name the account and, for
 * proxy auth, the identity that was refused — both of which an operator needs to
 * see in a log. Nothing else about the exchange is recorded anywhere; see
 * {@see ProtocolTrace} for the same rule on the wire level.
 */
final class AuthenticationFailed extends MailstoreError
{
    public static function forAdministrator(string $administrator, string $serverText): self
    {
        return new self(
            ErrorCode::AUTHENTICATION_FAILED,
            sprintf('The server refused the administrator "%s": %s', $administrator, $serverText),
        );
    }

    /**
     * `authcid` was accepted, `authzid` was not — the administrator is not
     * allowed to act for that user (architecture 9.3).
     */
    public static function forProxy(string $administrator, string $target, string $serverText): self
    {
        return new self(
            ErrorCode::AUTHENTICATION_FAILED,
            sprintf(
                'The server refused "%s" acting for "%s": %s',
                $administrator,
                $target,
                $serverText,
            ),
        );
    }

    public static function mechanismUnavailable(string $mechanism, string $endpoint): self
    {
        return new self(
            ErrorCode::AUTHENTICATION_FAILED,
            sprintf('%s does not offer the SASL mechanism %s', $endpoint, $mechanism),
        );
    }

    /**
     * Credentials that were wrong a second ago are still wrong. Retrying an
     * authentication failure is also the fastest way into a lockout.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use RuntimeException;
use Throwable;

/**
 * A presented access token was not accepted.
 *
 * Every one of these becomes the same `401` with `request.unauthenticated`. The
 * distinction the named constructors draw is for the **log**, not for the
 * client: telling a caller whether their token expired, was signed with an
 * unknown key or names the wrong audience is telling them how close they are.
 *
 * **No message contains the token, a part of it or a signature** (U-28). The
 * subject and the key id do appear, because those are what makes a log line
 * useful when an administrator reports being signed out.
 */
final class TokenRejected extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missing(): self
    {
        return new self('missing', 'The request carries no bearer token.');
    }

    public static function malformed(): self
    {
        return new self('malformed', 'The Authorization header is not a bearer token.');
    }

    /**
     * The library refused it — bad signature, unknown `kid`, expired, not yet
     * valid, unparsable. Its message is chained for the log and never shown.
     */
    public static function unverifiable(Throwable $cause): self
    {
        return new self(
            'unverifiable',
            sprintf('The token did not verify (%s).', $cause::class),
            $cause,
        );
    }

    public static function wrongIssuer(): self
    {
        return new self('wrong_issuer', 'The token was issued by something else.');
    }

    public static function wrongAudience(): self
    {
        return new self('wrong_audience', 'The token was not minted for this API.');
    }

    public static function incomplete(string $claim): self
    {
        return new self('incomplete', sprintf('The token has no usable "%s" claim.', $claim));
    }

    public static function unknownRole(string $value): self
    {
        return new self(
            'unknown_role',
            sprintf('The token claims the role "%s", which this application does not know.', $value),
        );
    }

    public static function revoked(string $subject): self
    {
        return new self(
            'revoked',
            sprintf('The token for "%s" is on the denylist.', $subject),
        );
    }

    public static function noSuchAdministrator(string $subject): self
    {
        return new self(
            'no_such_administrator',
            sprintf('The token names "%s", who cannot act — removed, or a domain master with no domain.', $subject),
        );
    }

    public static function roleChanged(string $subject): self
    {
        return new self(
            'role_changed',
            sprintf('The role of "%s" has changed since this token was issued.', $subject),
        );
    }
}

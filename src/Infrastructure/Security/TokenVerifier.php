<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Runtime\Clock;
use Throwable;

/**
 * Turns a token string into {@see TokenClaims}, or refuses.
 *
 * Signature, `exp`, `iat` and `kid` are checked by the library; `iss` and `aud`
 * are checked here, because `firebase/php-jwt` does not. Leaving them unchecked
 * is the classic way a token minted for one service becomes valid at another —
 * and two installations that were given the same key by a copied `.env` are
 * exactly that case.
 *
 * ## Clock skew
 *
 * Thirty seconds of leeway on `exp` and `iat`, and no more. It covers hosts
 * whose clocks have drifted a little between the issuer and the verifier — the
 * same host today, but not necessarily forever. It is small enough that an
 * expired token is not usefully extended by it. Anything larger would be a
 * quiet increase of the token lifetime that E-15's trade-off depends on.
 *
 * `JWT::$leeway` is a static property, which is the library's only way to say
 * this. It is set on every call rather than once in the constructor so that a
 * second component cannot leave a different value behind.
 */
final readonly class TokenVerifier
{
    /** Seconds of tolerated clock difference on `exp` and `iat`. */
    public const int LEEWAY_SECONDS = 30;

    public function __construct(
        private SigningKeys $keys,
        private Clock $clock,
        private string $issuer,
        private string $audience,
    ) {
    }

    /**
     * @throws TokenRejected
     */
    public function verify(string $token): TokenClaims
    {
        if (trim($token) === '') {
            throw TokenRejected::missing();
        }

        // Both are static properties, which is the library's only way to say
        // either. They are set on every call rather than once in the
        // constructor, so that another component cannot leave a different value
        // behind.
        //
        // `$timestamp` matters more than it looks: without it the library
        // compares `exp` against `time()` while the issuer stamps `exp` from the
        // injected clock. The two would agree in production and disagree in
        // every test — which is to say the clock abstraction would be decorative
        // on exactly the half where expiry is decided.
        JWT::$leeway = self::LEEWAY_SECONDS;
        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            $decoded = JWT::decode($token, $this->keys->verificationKeys());
        } catch (Throwable $error) {
            throw TokenRejected::unverifiable($error);
        }

        /** @var array<string, mixed> $claims */
        $claims = (array) $decoded;

        if (self::stringClaim($claims, 'iss') !== $this->issuer) {
            throw TokenRejected::wrongIssuer();
        }

        if (!self::audienceMatches($claims, $this->audience)) {
            throw TokenRejected::wrongAudience();
        }

        $subject = self::stringClaim($claims, 'sub');
        $jti = self::stringClaim($claims, 'jti');
        $role = self::stringClaim($claims, 'role');

        if ($subject === null || $subject === '') {
            throw TokenRejected::incomplete('sub');
        }

        if ($jti === null || $jti === '') {
            throw TokenRejected::incomplete('jti');
        }

        if ($role === null || $role === '') {
            throw TokenRejected::incomplete('role');
        }

        return new TokenClaims(
            subject: $subject,
            role: AdminRole::tryFrom($role) ?? throw TokenRejected::unknownRole($role),
            jti: $jti,
            issuedAt: self::timeClaim($claims, 'iat'),
            expiresAt: self::timeClaim($claims, 'exp'),
        );
    }

    /**
     * The clock this verifier reads, so that a caller can compare against the
     * same one it just used rather than reaching for `time()`.
     */
    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * `aud` may be a string or a list of strings per RFC 7519. Both are handled;
     * anything else is not an audience.
     *
     * @param array<string, mixed> $claims
     */
    private static function audienceMatches(array $claims, string $expected): bool
    {
        /** @var mixed $audience */
        $audience = $claims['aud'] ?? null;

        if (is_string($audience)) {
            return hash_equals($expected, $audience);
        }

        if (!is_array($audience)) {
            return false;
        }

        /** @var mixed $entry */
        foreach ($audience as $entry) {
            if (is_string($entry) && hash_equals($expected, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function stringClaim(array $claims, string $name): ?string
    {
        /** @var mixed $value */
        $value = $claims[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `iat` and `exp` are always present by the time this runs — the library
     * refuses a token without `exp`, and `iat` is written by our issuer. A token
     * from an older issuer without `iat` would still verify, and treating its
     * age as "the epoch" is the safe reading: a subject-wide revocation with any
     * cutoff at all then covers it.
     *
     * @param array<string, mixed> $claims
     */
    private static function timeClaim(array $claims, string $name): DateTimeImmutable
    {
        /** @var mixed $value */
        $value = $claims[$name] ?? null;

        return (new DateTimeImmutable('@' . (is_int($value) ? $value : 0)))
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}

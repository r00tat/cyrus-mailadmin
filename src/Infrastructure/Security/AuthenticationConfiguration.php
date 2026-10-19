<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Mailadmin\Config\Config;

/**
 * The signing keys and the two lifetimes, read once at startup (U-11).
 *
 * ## Nothing here has a working default
 *
 * `MAILADMIN_JWT_SIGNING_KEY` has to be set. There is no generated-on-startup
 * fallback, because a key that changes when the process restarts signs everyone
 * out at every deployment and, worse, makes that look like a bug elsewhere. And
 * there is no shipped default, because a shipped signing key is a shipped
 * administrator account.
 *
 * ## The lifetimes have bounds, not just defaults
 *
 * Sixty minutes for the access token and twelve hours for the refresh family
 * are what architecture 5.2 decided. They are configurable within a range so
 * that an installation can be *stricter*, and bounded so that it cannot quietly
 * become the thing E-15 traded against: the access token sits in `localStorage`,
 * and its short life is half of what makes that acceptable.
 *
 * ## Issuer and audience
 *
 * Both default to constants rather than to the host name. A host name in the
 * claim would change when the installation moves and invalidate every token in
 * the field for no security gain; the audience check exists to catch a token
 * from a *different service*, which a fixed string does perfectly well.
 */
final readonly class AuthenticationConfiguration
{
    public const string SIGNING_KEY = 'MAILADMIN_JWT_SIGNING_KEY';
    public const string PREVIOUS_SIGNING_KEY = 'MAILADMIN_JWT_SIGNING_KEY_PREVIOUS';
    public const string ISSUER = 'MAILADMIN_JWT_ISSUER';
    public const string AUDIENCE = 'MAILADMIN_JWT_AUDIENCE';
    public const string ACCESS_TOKEN_TTL = 'MAILADMIN_ACCESS_TOKEN_TTL';
    public const string REFRESH_TOKEN_TTL = 'MAILADMIN_REFRESH_TOKEN_TTL';

    public const string DEFAULT_ISSUER = 'mailadmin';
    public const string DEFAULT_AUDIENCE = 'mailadmin-api';

    /** Sixty minutes (architecture 5.2). */
    public const int DEFAULT_ACCESS_TOKEN_TTL = 3600;

    /** Twelve hours, absolute (architecture 5.2). */
    public const int DEFAULT_REFRESH_TOKEN_TTL = 43200;

    public const int MIN_ACCESS_TOKEN_TTL = 300;
    public const int MAX_ACCESS_TOKEN_TTL = 3600;
    public const int MIN_REFRESH_TOKEN_TTL = 900;
    public const int MAX_REFRESH_TOKEN_TTL = 43200;

    private function __construct(
        public SigningKeys $keys,
        public string $issuer,
        public string $audience,
        public int $accessTokenTtl,
        public int $refreshTokenTtl,
    ) {
    }

    /**
     * @throws ConfigurationInvalid
     */
    public static function fromConfig(Config $config): self
    {
        if (!$config->has(self::SIGNING_KEY)) {
            throw ConfigurationInvalid::signingKeyMissing(self::SIGNING_KEY);
        }

        $current = SigningKey::fromBase64Seed($config->string(self::SIGNING_KEY));
        $previous = $config->has(self::PREVIOUS_SIGNING_KEY)
            ? SigningKey::fromBase64Seed($config->string(self::PREVIOUS_SIGNING_KEY))
            : null;

        return new self(
            keys: SigningKeys::of($current, $previous),
            issuer: $config->stringOrDefault(self::ISSUER, self::DEFAULT_ISSUER),
            audience: $config->stringOrDefault(self::AUDIENCE, self::DEFAULT_AUDIENCE),
            accessTokenTtl: self::lifetime(
                $config,
                self::ACCESS_TOKEN_TTL,
                self::DEFAULT_ACCESS_TOKEN_TTL,
                self::MIN_ACCESS_TOKEN_TTL,
                self::MAX_ACCESS_TOKEN_TTL,
            ),
            refreshTokenTtl: self::lifetime(
                $config,
                self::REFRESH_TOKEN_TTL,
                self::DEFAULT_REFRESH_TOKEN_TTL,
                self::MIN_REFRESH_TOKEN_TTL,
                self::MAX_REFRESH_TOKEN_TTL,
            ),
        );
    }

    /**
     * @throws ConfigurationInvalid
     */
    private static function lifetime(
        Config $config,
        string $variable,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $seconds = $config->intOrDefault($variable, $default);

        if ($seconds < $minimum || $seconds > $maximum) {
            throw ConfigurationInvalid::lifetimeOutOfRange($variable, $seconds, $minimum, $maximum);
        }

        return $seconds;
    }
}

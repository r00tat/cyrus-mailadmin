<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use SensitiveParameter;
use SodiumException;

/**
 * One Ed25519 key pair, derived from the 32-byte seed an operator configures.
 *
 * ## Why a seed and not a key pair
 *
 * Ed25519's secret key is 64 bytes, of which the second half is the public key
 * — it is derived, not chosen. Configuring the seed means there is exactly one
 * value in the environment, it is 32 bytes like every other secret, and the two
 * halves cannot be configured inconsistently with each other. Generating one:
 *
 *     openssl rand -base64 32
 *
 * ## The key id is derived, not configured
 *
 * `kid` is the first 16 hex characters of the SHA-256 of the **public** key. It
 * is therefore stable for a given key, different for different keys, and needs
 * no second environment variable that an operator could forget to change when
 * rotating. It reveals nothing: the public key is public by construction, and a
 * truncated digest of it is not a shortcut to the private half.
 *
 * ## What is exposed
 *
 * {@see verificationMaterial()} — the public key, safe to print. Its counterpart
 * {@see signingMaterial()} is the secret key and is deliberately a method rather
 * than a property so that nothing serialises it by accident; `__debugInfo()`
 * hides it as well.
 *
 * Both are base64, because that is the shape `firebase/php-jwt` reads for
 * `EdDSA`.
 */
final class SigningKey
{
    /** Ed25519 seeds are exactly this long. Anything else is a configuration error. */
    public const int SEED_BYTES = 32;

    private function __construct(
        public readonly string $kid,
        private readonly string $secretKey,
        private readonly string $publicKey,
    ) {
    }

    /**
     * @param string $base64Seed 32 random bytes, base64 encoded
     *
     * @throws ConfigurationInvalid when the value is not a usable seed
     */
    public static function fromBase64Seed(#[SensitiveParameter] string $base64Seed): self
    {
        $seed = base64_decode(trim($base64Seed), true);

        if ($seed === false) {
            throw ConfigurationInvalid::signingKeyNotBase64();
        }

        if (strlen($seed) !== self::SEED_BYTES) {
            throw ConfigurationInvalid::signingKeyWrongLength(strlen($seed));
        }

        try {
            $pair = sodium_crypto_sign_seed_keypair($seed);
            $secret = sodium_crypto_sign_secretkey($pair);
            $public = sodium_crypto_sign_publickey($pair);
        } catch (SodiumException $error) {
            // Nothing about the seed goes into the message; the exception from
            // libsodium is not passed on either, for the same reason.
            throw ConfigurationInvalid::signingKeyUnusable($error);
        } finally {
            sodium_memzero($seed);
        }

        return new self(
            kid: substr(hash('sha256', $public), 0, 16),
            secretKey: base64_encode($secret),
            publicKey: base64_encode($public),
        );
    }

    /**
     * The secret half, for {@see TokenIssuer} and nothing else.
     */
    public function signingMaterial(): string
    {
        return $this->secretKey;
    }

    /**
     * The public half, for {@see TokenVerifier}. Safe to log, safe to publish.
     */
    public function verificationMaterial(): string
    {
        return $this->publicKey;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'kid' => $this->kid,
            'secretKey' => '[redacted]',
            'publicKey' => $this->publicKey,
        ];
    }
}

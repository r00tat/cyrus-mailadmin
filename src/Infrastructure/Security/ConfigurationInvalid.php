<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use RuntimeException;
use Throwable;

/**
 * The authentication configuration is missing, malformed or unsafe.
 *
 * Raised while the container is built, not on the first request that needs a
 * token. A signing key that is absent or the wrong length is a deployment fault,
 * and the useful moment to say so is the one where the operator is still
 * watching — a `500` on the first sign-in three days later is not.
 *
 * **No message names a key, a seed or any part of one** (U-28). A length is
 * stated because it is what an operator needs to fix the mistake and it says
 * nothing about the value.
 */
final class ConfigurationInvalid extends RuntimeException
{
    public static function signingKeyMissing(string $variable): self
    {
        return new self(sprintf(
            '%s is not set. Generate one with `openssl rand -base64 32`. There is no built-in '
            . 'default and no fallback to an ephemeral key: a key that changes on every restart '
            . 'would sign out everyone at every deployment, and a shipped default would be worse.',
            $variable,
        ));
    }

    public static function signingKeyNotBase64(): self
    {
        return new self(
            'The signing key is not valid base64. Expected 32 random bytes, base64 encoded — '
            . '`openssl rand -base64 32`.',
        );
    }

    public static function signingKeyWrongLength(int $bytes): self
    {
        return new self(sprintf(
            'The signing key decodes to %d bytes; Ed25519 needs exactly %d. A shorter value is '
            . 'not padded and a longer one is not truncated, because either would silently '
            . 'weaken the key.',
            $bytes,
            SigningKey::SEED_BYTES,
        ));
    }

    /**
     * libsodium refused the seed. The original exception is not chained on
     * purpose: its message can quote the input.
     */
    public static function signingKeyUnusable(Throwable $cause): self
    {
        return new self(sprintf(
            'libsodium could not derive a key pair from the configured seed (%s).',
            $cause::class,
        ));
    }

    public static function previousKeyEqualsCurrent(): self
    {
        return new self(
            'The previous signing key is the same as the current one. Rotation would then be a '
            . 'no-op that looks like it worked; set it to the key being retired, or unset it.',
        );
    }

    public static function lifetimeOutOfRange(string $variable, int $seconds, int $minimum, int $maximum): self
    {
        return new self(sprintf(
            '%s is %d seconds, outside the accepted %d..%d. The bounds are not taste: E-15 '
            . 'trades an access token in localStorage against a short lifetime, and raising it '
            . 'to save sign-ins is the one change that makes that trade a bad one.',
            $variable,
            $seconds,
            $minimum,
            $maximum,
        ));
    }
}

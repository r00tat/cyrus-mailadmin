<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use SensitiveParameter;

/**
 * An opaque refresh token: 256 bits from the CSPRNG, and the digest it is
 * stored under.
 *
 * ## Opaque, not a JWT
 *
 * A refresh token is checked against the database on every use anyway — that is
 * how rotation and reuse detection work — so there is nothing for a signature to
 * save. A random string has no claims to get stale, no algorithm to confuse and
 * nothing readable in it if it turns up in a proxy log.
 *
 * ## SHA-256 for storage, not Argon2id
 *
 * The stored value is a plain SHA-256. That is the right choice **because** the
 * input is 256 bits of uniform randomness: there is no dictionary to run and no
 * work factor worth paying. It also has to be looked up by value, and a salted
 * hash cannot be. For a *password* the reasoning is the opposite, which is why
 * {@see PasswordHasher} exists and is not used here.
 *
 * Base64url, so the value survives a cookie, a JSON body and a URL unchanged.
 */
final readonly class RefreshToken
{
    /** 256 bits. Architecture 5.2 names the number. */
    public const int BYTES = 32;

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        return new self(self::encode(random_bytes(self::BYTES)));
    }

    /**
     * A value that arrived from a client. Not validated here beyond being
     * non-empty — whether it means anything is the store's answer, and a
     * syntactic pre-check would only leak that the format is right.
     */
    public static function fromString(#[SensitiveParameter] string $value): self
    {
        return new self($value);
    }

    public function hash(): string
    {
        return self::hashOf($this->value);
    }

    public static function hashOf(#[SensitiveParameter] string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

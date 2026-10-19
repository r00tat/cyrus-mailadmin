<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Firebase\JWT\Key;

/**
 * The keys the application signs with and the keys it still accepts.
 *
 * Rotation without signing everybody out is the whole reason this class exists.
 * A new key is put in `MAILADMIN_JWT_SIGNING_KEY` and the old one moves to
 * `MAILADMIN_JWT_SIGNING_KEY_PREVIOUS`; from that moment new tokens are signed
 * with the new key and tokens already in the field keep verifying until they
 * expire, which is at most an hour later. The previous key is then removed.
 *
 * Every token carries its `kid` in the header, so verification is a lookup
 * rather than a try-both loop. That is not only tidier: trying keys in turn
 * would mean a token signed with a retired key and one with a corrupt signature
 * take different amounts of work, and the difference is measurable.
 *
 * **The previous key never signs anything.** It is verification material only,
 * which is what makes retiring it a one-line change rather than a migration.
 */
final readonly class SigningKeys
{
    private function __construct(
        public SigningKey $current,
        public ?SigningKey $previous,
    ) {
    }

    /**
     * @throws ConfigurationInvalid
     */
    public static function of(SigningKey $current, ?SigningKey $previous = null): self
    {
        if ($previous !== null && $previous->kid === $current->kid) {
            throw ConfigurationInvalid::previousKeyEqualsCurrent();
        }

        return new self($current, $previous);
    }

    /**
     * Verification keys by `kid`, in the shape `firebase/php-jwt` wants.
     *
     * @return array<string, Key>
     */
    public function verificationKeys(): array
    {
        $keys = [$this->current->kid => new Key($this->current->verificationMaterial(), TokenIssuer::ALGORITHM)];

        if ($this->previous !== null) {
            $keys[$this->previous->kid] = new Key(
                $this->previous->verificationMaterial(),
                TokenIssuer::ALGORITHM,
            );
        }

        return $keys;
    }

    /**
     * The key ids that verify today — for a diagnostic line, not for a client.
     *
     * @return list<string>
     */
    public function knownKeyIds(): array
    {
        return array_keys($this->verificationKeys());
    }
}

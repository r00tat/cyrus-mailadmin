<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Firebase\JWT\JWT;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Runtime\Clock;

/**
 * Mints access tokens (architecture 5.3).
 *
 * ## EdDSA, not HS256
 *
 * Asymmetric signing costs a little more per token and buys one thing that
 * matters later: a second service — a monitoring probe, a future CLI, the
 * frontend's own build-time checks — can be given the public key and verify
 * tokens without being able to mint them. With a shared secret, "may verify"
 * and "may impersonate any administrator" are the same permission.
 *
 * ## What goes in, and what deliberately does not
 *
 * `iss`, `aud`, `sub`, `role`, `jti`, `iat`, `exp`. No domain scope, no display
 * name, no email address, no permissions list. A JWT is signed, not encrypted:
 * everything in it is readable by anyone holding the token, and by anyone who
 * finds it in a proxy log. The claim set is the minimum that lets a request be
 * attributed without a database read of its own — everything else is looked up
 * (architecture 5.3).
 *
 * `aud` is checked on the way back in. It is what stops a token minted for a
 * different service, or a different installation sharing a key by mistake, from
 * being accepted here.
 */
final readonly class TokenIssuer
{
    /** Ed25519. The one algorithm this application signs and verifies with. */
    public const string ALGORITHM = 'EdDSA';

    /**
     * Bytes of randomness in a `jti`. 128 bits is far more than collision
     * resistance needs for an hour's worth of tokens; it is cheap and it means
     * the denylist key cannot be guessed by anyone wanting to pre-emptively
     * revoke somebody else's session.
     */
    private const int JTI_BYTES = 16;

    public function __construct(
        private SigningKeys $keys,
        private Clock $clock,
        private string $issuer,
        private string $audience,
        private int $lifetimeSeconds,
    ) {
    }

    public function issue(string $subject, AdminRole $role): AccessToken
    {
        $now = $this->clock->now();
        // setTimestamp rather than modify(): no string is parsed, so there is no
        // parse failure to handle and no locale to get in the way.
        $expiresAt = $now->setTimestamp($now->getTimestamp() + $this->lifetimeSeconds);
        $jti = bin2hex(random_bytes(self::JTI_BYTES));

        $token = JWT::encode(
            [
                'iss' => $this->issuer,
                'aud' => $this->audience,
                'sub' => $subject,
                'role' => $role->value,
                'jti' => $jti,
                'iat' => $now->getTimestamp(),
                'exp' => $expiresAt->getTimestamp(),
            ],
            $this->keys->current->signingMaterial(),
            self::ALGORITHM,
            // The key id travels in the header so that verification is a lookup
            // rather than a try-every-key loop (see SigningKeys).
            $this->keys->current->kid,
        );

        return new AccessToken(
            value: $token,
            jti: $jti,
            issuedAt: $now,
            expiresAt: $expiresAt,
        );
    }

    public function lifetimeSeconds(): int
    {
        return $this->lifetimeSeconds;
    }
}

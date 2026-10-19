<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Infrastructure\Security\AccessToken;
use Mailadmin\Infrastructure\Security\SigningKey;
use Mailadmin\Infrastructure\Security\SigningKeys;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Infrastructure\Security\TokenRejected;
use Mailadmin\Infrastructure\Security\TokenVerifier;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Issuing and verifying, including every way verification must fail.
 *
 * The negative cases carry the weight. A verifier that accepts a token it should
 * not is not a failing test somewhere — it is an authentication bypass, and the
 * three classic ways to build one are an unchecked audience, an unchecked
 * issuer, and a signature checked against a key the token itself chose.
 */
#[CoversClass(TokenIssuer::class)]
#[CoversClass(TokenVerifier::class)]
#[CoversClass(TokenClaims::class)]
#[CoversClass(AccessToken::class)]
#[CoversClass(TokenRejected::class)]
final class TokenRoundTripTest extends TestCase
{
    private const string SEED_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const string SEED_B = 'QkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkI=';
    private const string SEED_C = 'Q0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0M=';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
    }

    public function testATokenComesBackAsWhatWentIn(): void
    {
        $token = $this->issuer()->issue('anna', AdminRole::DomainMaster);
        $claims = $this->verifier()->verify($token->value);

        self::assertSame('anna', $claims->subject);
        self::assertSame(AdminRole::DomainMaster, $claims->role);
        self::assertSame($token->jti, $claims->jti);
        self::assertSame($token->expiresAt->getTimestamp(), $claims->expiresAt->getTimestamp());
    }

    /**
     * Two tokens issued in the same second must still be distinguishable, or the
     * denylist would revoke more than the one that was signed out.
     */
    public function testEveryTokenGetsItsOwnIdentifier(): void
    {
        $issuer = $this->issuer();

        self::assertNotSame(
            $issuer->issue('anna', AdminRole::Superuser)->jti,
            $issuer->issue('anna', AdminRole::Superuser)->jti,
        );
    }

    /**
     * Architecture 5.3: the claim set is deliberately minimal. A JWT is signed,
     * not encrypted — anything put in it is readable by whoever holds it and by
     * whatever logged it.
     */
    public function testTheTokenCarriesNothingBeyondTheSevenClaims(): void
    {
        $token = $this->issuer()->issue('anna', AdminRole::DomainMaster);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(self::decodeSegment($token->value, 1), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            ['aud', 'exp', 'iat', 'iss', 'jti', 'role', 'sub'],
            self::sortedKeys($payload),
        );
    }

    /**
     * The domain scope must not be in there: it is read per request precisely so
     * that withdrawing an assignment takes effect at once (architecture 5.3).
     *
     * Asserted on the claim names rather than by searching the payload for the
     * word — the role of a domain master *is* `domainmaster`, so a substring
     * search would fail on a token that is entirely correct.
     */
    public function testTheTokenCarriesNoDomainScope(): void
    {
        $token = $this->issuer()->issue('anna', AdminRole::DomainMaster);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(self::decodeSegment($token->value, 1), true, flags: JSON_THROW_ON_ERROR);

        foreach (['scope', 'domains', 'domain', 'permissions'] as $claim) {
            self::assertArrayNotHasKey($claim, $payload);
        }
    }

    public function testTheKeyIdIsInTheHeaderSoVerificationIsALookup(): void
    {
        $token = $this->issuer()->issue('anna', AdminRole::Superuser);

        /** @var array<string, mixed> $header */
        $header = json_decode(self::decodeSegment($token->value, 0), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(SigningKey::fromBase64Seed(self::SEED_A)->kid, $header['kid']);
        self::assertSame('EdDSA', $header['alg']);
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $token = $this->issuer()->issue('anna', AdminRole::Superuser);

        // An hour, the leeway, and a second past it.
        $this->clock->advance(3600 + TokenVerifier::LEEWAY_SECONDS + 1);

        $this->expectException(TokenRejected::class);

        $this->verifier()->verify($token->value);
    }

    /**
     * The leeway exists for two hosts whose clocks have drifted slightly. Thirty
     * seconds, and no more — a larger value would be a quiet extension of the
     * lifetime E-15's trade-off rests on.
     */
    public function testATokenJustPastExpiryStillVerifiesWithinTheLeeway(): void
    {
        $token = $this->issuer()->issue('anna', AdminRole::Superuser);

        $this->clock->advance(3600 + TokenVerifier::LEEWAY_SECONDS - 5);

        self::assertSame('anna', $this->verifier()->verify($token->value)->subject);
    }

    public function testATokenSignedWithAnotherKeyIsRefused(): void
    {
        $foreign = new TokenIssuer(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED_C)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
            3600,
        );

        $this->expectException(TokenRejected::class);

        $this->verifier()->verify($foreign->issue('anna', AdminRole::Superuser)->value);
    }

    /**
     * The token names the key it was signed with, so a token naming a key we do
     * not have must be refused rather than tried against the ones we do.
     */
    public function testATokenNamingAnUnknownKeyIsRefused(): void
    {
        $tampered = self::withHeaderKid(
            $this->issuer()->issue('anna', AdminRole::Superuser)->value,
            'deadbeefdeadbeef',
        );

        $this->expectException(TokenRejected::class);

        $this->verifier()->verify($tampered);
    }

    /**
     * The rotation case: a token issued under the retiring key keeps working
     * until it expires, which is what makes rotating a key a non-event.
     */
    public function testATokenFromThePreviousKeyStillVerifies(): void
    {
        $old = new TokenIssuer(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED_B)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
            3600,
        );

        $afterRotation = new TokenVerifier(
            SigningKeys::of(
                SigningKey::fromBase64Seed(self::SEED_A),
                SigningKey::fromBase64Seed(self::SEED_B),
            ),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
        );

        self::assertSame('anna', $afterRotation->verify($old->issue('anna', AdminRole::Superuser)->value)->subject);
    }

    /**
     * `firebase/php-jwt` does not check `aud`. Leaving it unchecked is how a
     * token minted for another service — or by another installation that was
     * handed the same key in a copied `.env` — becomes valid here.
     */
    public function testATokenForAnotherAudienceIsRefused(): void
    {
        $elsewhere = new TokenIssuer(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED_A)),
            $this->clock,
            'mailadmin',
            'some-other-service',
            3600,
        );

        $this->expectException(TokenRejected::class);
        $this->expectExceptionMessageMatches('/not minted for this API/');

        $this->verifier()->verify($elsewhere->issue('anna', AdminRole::Superuser)->value);
    }

    public function testATokenFromAnotherIssuerIsRefused(): void
    {
        $elsewhere = new TokenIssuer(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED_A)),
            $this->clock,
            'something-else',
            'mailadmin-api',
            3600,
        );

        $this->expectException(TokenRejected::class);
        $this->expectExceptionMessageMatches('/issued by something else/');

        $this->verifier()->verify($elsewhere->issue('anna', AdminRole::Superuser)->value);
    }

    public function testAnEmptyTokenIsRefusedWithoutReachingTheLibrary(): void
    {
        $this->expectException(TokenRejected::class);
        $this->expectExceptionMessageMatches('/no bearer token/');

        $this->verifier()->verify('   ');
    }

    public function testGarbageIsRefused(): void
    {
        $this->expectException(TokenRejected::class);

        $this->verifier()->verify('not.a.token');
    }

    public function testTheTokenStringIsNotInADebugDump(): void
    {
        $token = $this->issuer()->issue('anna', AdminRole::Superuser);

        self::assertStringNotContainsString($token->value, var_export($token->__debugInfo(), true));
    }

    private function issuer(): TokenIssuer
    {
        return new TokenIssuer(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED_A)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
            3600,
        );
    }

    private function verifier(): TokenVerifier
    {
        return new TokenVerifier(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED_A)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
        );
    }

    private static function decodeSegment(string $token, int $index): string
    {
        $segments = explode('.', $token);

        return (string) base64_decode(strtr($segments[$index], '-_', '+/'), true);
    }

    /**
     * Rewrites the header so that the token claims a key id nobody has. The
     * signature is left alone — it would not verify either way, and the point is
     * that the lookup fails first.
     */
    private static function withHeaderKid(string $token, string $kid): string
    {
        $segments = explode('.', $token);

        /** @var array<string, mixed> $header */
        $header = json_decode(self::decodeSegment($token, 0), true, flags: JSON_THROW_ON_ERROR);
        $header['kid'] = $kid;

        $segments[0] = rtrim(strtr(base64_encode(json_encode($header, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return implode('.', $segments);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private static function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Infrastructure\Security\ConfigurationInvalid;
use Mailadmin\Infrastructure\Security\SigningKey;
use Mailadmin\Infrastructure\Security\SigningKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The key material, and the refusals around it.
 *
 * Everything here is about failing loudly at startup. A signing key that is
 * quietly padded, truncated or invented is the kind of defect that produces a
 * working application with worthless tokens.
 */
#[CoversClass(SigningKey::class)]
#[CoversClass(SigningKeys::class)]
#[CoversClass(ConfigurationInvalid::class)]
final class SigningKeyTest extends TestCase
{
    private const string SEED_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const string SEED_B = 'QkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkI=';

    public function testAKeyIsDerivedFromItsSeedAndNothingElse(): void
    {
        $first = SigningKey::fromBase64Seed(self::SEED_A);
        $second = SigningKey::fromBase64Seed(self::SEED_A);

        self::assertSame($first->kid, $second->kid);
        self::assertSame($first->verificationMaterial(), $second->verificationMaterial());
        self::assertSame($first->signingMaterial(), $second->signingMaterial());
    }

    public function testDifferentSeedsProduceDifferentKeyIds(): void
    {
        self::assertNotSame(
            SigningKey::fromBase64Seed(self::SEED_A)->kid,
            SigningKey::fromBase64Seed(self::SEED_B)->kid,
        );
    }

    /**
     * The key id has to travel in a JWT header and be usable as an array key, so
     * anything that is not short printable hex would be a problem later rather
     * than here.
     */
    public function testTheKeyIdIsShortHex(): void
    {
        $kid = SigningKey::fromBase64Seed(self::SEED_A)->kid;

        self::assertSame(16, strlen($kid));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $kid);
    }

    /**
     * Ed25519's secret key is 64 bytes and its public key 32. Getting these the
     * wrong way round produces a key pair that signs nothing verifiable, and the
     * failure would surface as "the token does not verify" — a long way from the
     * cause.
     */
    public function testTheTwoHalvesHaveTheLengthsEd25519Prescribes(): void
    {
        $key = SigningKey::fromBase64Seed(self::SEED_A);

        self::assertSame(64, strlen((string) base64_decode($key->signingMaterial(), true)));
        self::assertSame(32, strlen((string) base64_decode($key->verificationMaterial(), true)));
    }

    public function testASeedThatIsNotBase64IsRefused(): void
    {
        $this->expectException(ConfigurationInvalid::class);
        $this->expectExceptionMessageMatches('/valid base64/');

        SigningKey::fromBase64Seed('not base64 at all !!!');
    }

    /**
     * Neither padded nor truncated: both would produce a usable-looking key with
     * less entropy than the operator believes they configured.
     */
    public function testASeedOfTheWrongLengthIsRefusedRatherThanAdjusted(): void
    {
        $this->expectException(ConfigurationInvalid::class);
        $this->expectExceptionMessageMatches('/decodes to 8 bytes/');

        SigningKey::fromBase64Seed(base64_encode('12345678'));
    }

    /**
     * The message may name a length — that is what an operator needs — but never
     * anything derived from the value itself.
     */
    public function testARefusalNamesNoPartOfTheKey(): void
    {
        $seed = base64_encode('this-is-not-thirty-two-bytes');

        try {
            SigningKey::fromBase64Seed($seed);
            self::fail('A short seed must be refused.');
        } catch (ConfigurationInvalid $error) {
            self::assertStringNotContainsString($seed, $error->getMessage());
            self::assertStringNotContainsString('this-is-not', $error->getMessage());
        }
    }

    public function testBothKeysVerifyWhileARotationIsInFlight(): void
    {
        $keys = SigningKeys::of(
            SigningKey::fromBase64Seed(self::SEED_A),
            SigningKey::fromBase64Seed(self::SEED_B),
        );

        self::assertCount(2, $keys->verificationKeys());
        self::assertContains(SigningKey::fromBase64Seed(self::SEED_B)->kid, $keys->knownKeyIds());
    }

    public function testWithoutARotationThereIsOneKey(): void
    {
        $keys = SigningKeys::of(SigningKey::fromBase64Seed(self::SEED_A));

        self::assertCount(1, $keys->verificationKeys());
        self::assertNull($keys->previous);
    }

    /**
     * A "rotation" to the same key is a no-op that looks like it worked, which
     * is the worst kind.
     */
    public function testRotatingToTheSameKeyIsRefused(): void
    {
        $this->expectException(ConfigurationInvalid::class);

        SigningKeys::of(
            SigningKey::fromBase64Seed(self::SEED_A),
            SigningKey::fromBase64Seed(self::SEED_A),
        );
    }

    public function testTheSecretHalfIsNotInADebugDump(): void
    {
        $key = SigningKey::fromBase64Seed(self::SEED_A);

        /** @var array<string, string> $dump */
        $dump = $key->__debugInfo();

        self::assertSame('[redacted]', $dump['secretKey']);
        self::assertStringNotContainsString($key->signingMaterial(), var_export($dump, true));
    }
}

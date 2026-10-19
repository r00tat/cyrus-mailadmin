<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Application\Audit;

use Mailadmin\Application\Audit\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The half of S4's definition of done that is a security property: **no entry
 * contains a plaintext password or a hash.**
 *
 * The tests are written as "the document afterwards does not contain the secret,
 * anywhere" rather than "field x equals `[redacted]`". The difference matters: a
 * masking rule that replaces the value but leaves a copy of it under a second
 * key would pass the second form and fail the first, and the second form is the
 * one that describes what was promised.
 */
#[CoversClass(Redactor::class)]
final class RedactorTest extends TestCase
{
    private const string SECRET = 'correct-horse-battery-staple';

    public function testAPasswordBecomesAPlaceholder(): void
    {
        $result = (new Redactor())->apply(['username' => 'anna', 'password' => self::SECRET]);

        self::assertSame(['username' => 'anna', 'password' => Redactor::REDACTED], $result);
    }

    /**
     * S4 words it precisely: password fields appear as a placeholder, hashes not
     * at all. A placeholder says "a password was set", which is worth recording;
     * a placeholder under `password_hash` would say the trail holds a credential
     * in a slower form, and it should not hold one at all.
     */
    public function testAHashIsNotMaskedButRemoved(): void
    {
        $result = (new Redactor())->apply([
            'username' => 'anna',
            'password_hash' => '$2y$10$abcdefghijklmnopqrstuv',
        ]);

        self::assertSame(['username' => 'anna'], $result);
    }

    #[DataProvider('secretKeys')]
    public function testEveryKeyTheLoggerTreatsAsSecretIsTreatedAsSecretHere(string $key): void
    {
        $document = (string) json_encode((new Redactor())->apply([$key => self::SECRET]));

        self::assertStringNotContainsString(self::SECRET, $document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretKeys(): iterable
    {
        foreach ([
            'password',
            'new_password',
            'old_password',
            'passwd',
            'passphrase',
            'secret',
            'access_token',
            'refresh_token',
            'authorization',
            'cookie',
            'credential',
            'api_key',
            'private_key',
            'password_hash',
            'passwordHash',
            'crypt',
        ] as $key) {
            yield $key => [$key];
        }
    }

    /**
     * E-13 and U-22 need the *format* of a legacy hash visible — that is the
     * whole mechanism by which the accounts still on DES-crypt are found. A
     * blanket rule keyed on "contains hash" would redact it.
     */
    public function testTheHashFormatIsMetadataAndStaysReadable(): void
    {
        $result = (new Redactor())->apply([
            'password_format' => 'des-crypt',
            'hash_format' => 'md5crypt',
        ]);

        self::assertSame(['password_format' => 'des-crypt', 'hash_format' => 'md5crypt'], $result);
    }

    #[DataProvider('storedCredentials')]
    public function testAValueThatIsRecognisablyACredentialGoesWhateverItIsCalled(string $value): void
    {
        $result = (new Redactor())->apply(['harmless_looking_field' => $value]);

        self::assertSame([], $result, 'A stored credential survived under an innocuous key.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storedCredentials(): iterable
    {
        yield 'bcrypt' => ['$2y$10$usesomesillystringfore2uDLvp1Ii2e./U9C8sBjqp8I90dH6hi'];
        yield 'md5crypt' => ['$1$abcdefgh$ijklmnopqrstuvwxyz01234'];
        yield 'sha512crypt' => ['$6$rounds=5000$abcdefgh$ijklmnop'];
        yield 'argon2id' => ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$aaaa'];
        yield 'rfc 2307 ssha' => ['{SSHA}0123456789abcdef0123456789abcdef01234567'];
        yield 'mysql password()' => ['*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19'];
    }

    public function testAnOrdinaryValueThatMerelyStartsWithADollarSignSurvives(): void
    {
        // The patterns are anchored on the crypt(3) *shapes*, not on `$`. A
        // display name or a quota note is not a credential.
        $result = (new Redactor())->apply(['note' => '$100 of storage', 'quota' => '$$$']);

        self::assertSame(['note' => '$100 of storage', 'quota' => '$$$'], $result);
    }

    public function testNestedStructuresAreWalked(): void
    {
        $result = (new Redactor())->apply([
            'account' => [
                'name' => 'anna',
                'services' => ['password' => self::SECRET, 'password_hash' => '$2y$10$aaaa'],
            ],
        ]);

        self::assertSame(
            ['account' => ['name' => 'anna', 'services' => ['password' => Redactor::REDACTED]]],
            $result,
        );
    }

    /**
     * A key that is itself secret masks everything under it rather than being
     * walked. `credentials` is a container of secrets, and a rule that recursed
     * into it would depend on every key inside also matching.
     */
    public function testASecretKeyHoldingAStructureIsMaskedWhole(): void
    {
        $result = (new Redactor())->apply([
            'credentials' => ['username' => 'anna', 'password' => self::SECRET],
        ]);

        self::assertSame(['credentials' => Redactor::REDACTED], $result);
    }

    public function testNullStaysNull(): void
    {
        // "There was no such state" and "the state was empty" are different
        // things, and the column has to keep them apart (U-61).
        self::assertNull((new Redactor())->apply(null));
        self::assertSame([], (new Redactor())->apply([]));
    }

    public function testAnObjectIsReplacedByItsClassNameRatherThanSerialised(): void
    {
        $result = (new Redactor())->apply(['value' => new \stdClass()]);

        self::assertSame(['value' => \stdClass::class], $result);
    }

    /**
     * A structure deeper than the limit is dropped, not walked. Neither a cycle
     * nor a pathological nesting may stall a write — and nothing may hide below
     * the floor either, which is why the replacement is a marker rather than the
     * remaining subtree.
     */
    public function testDeepNestingIsTruncatedRatherThanFollowed(): void
    {
        $deep = ['password' => self::SECRET];
        for ($i = 0; $i < 20; ++$i) {
            $deep = ['level' => $deep];
        }

        $document = (string) json_encode((new Redactor())->apply($deep));

        self::assertStringNotContainsString(self::SECRET, $document);
        self::assertStringContainsString('_truncated', $document);
    }

    public function testTheOrdinaryFieldsOfARepresentationAreUntouched(): void
    {
        $representation = [
            'name' => 'example.test',
            'max_accounts' => 20,
            'quota' => 20000,
            'free_names' => false,
            'folders' => ['Sent', 'Trash'],
        ];

        self::assertSame($representation, (new Redactor())->apply($representation));
    }
}

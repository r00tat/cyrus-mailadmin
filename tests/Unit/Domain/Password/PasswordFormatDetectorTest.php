<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Password;

use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\PasswordFormatDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every row of architecture 5.5, plus the ambiguous cases the legacy data
 * contains.
 *
 * All hashes here are produced in the test, from throw-away passwords. Nothing
 * comes from `docs/internal/` or from the production database.
 */
#[CoversClass(PasswordFormatDetector::class)]
final class PasswordFormatDetectorTest extends TestCase
{
    #[DataProvider('hashes')]
    public function testTheFormatIsRecognisedFromTheHashAlone(string $hash, PasswordFormat $expected): void
    {
        self::assertSame($expected, PasswordFormatDetector::detect($hash));
    }

    /**
     * @return iterable<string, array{string, PasswordFormat}>
     */
    public static function hashes(): iterable
    {
        yield 'argon2id' => [
            password_hash('a-throw-away-secret', PASSWORD_ARGON2ID),
            PasswordFormat::Argon2id,
        ];
        yield 'bcrypt $2y$' => [
            password_hash('a-throw-away-secret', PASSWORD_BCRYPT, ['cost' => 4]),
            PasswordFormat::Bcrypt,
        ];
        // Written by other tools; crypt(3) reads all of them.
        yield 'bcrypt $2a$' => ['$2a$04$abcdefghijklmnopqrstuuKk9Z8L0m1n2o3p4q5r6s7t8u9v0w1x', PasswordFormat::Bcrypt];
        yield 'bcrypt $2b$' => ['$2b$04$abcdefghijklmnopqrstuuKk9Z8L0m1n2o3p4q5r6s7t8u9v0w1x', PasswordFormat::Bcrypt];
        yield 'bcrypt $2x$' => ['$2x$04$abcdefghijklmnopqrstuuKk9Z8L0m1n2o3p4q5r6s7t8u9v0w1x', PasswordFormat::Bcrypt];

        yield 'md5crypt' => [(string) crypt('a-throw-away-secret', '$1$abcdefgh$'), PasswordFormat::Md5Crypt];
        yield 'sha256crypt' => [(string) crypt('a-throw-away-secret', '$5$abcdefgh$'), PasswordFormat::Sha256Crypt];
        yield 'sha512crypt' => [(string) crypt('a-throw-away-secret', '$6$abcdefgh$'), PasswordFormat::Sha512Crypt];

        yield 'des-crypt' => [(string) crypt('a-throw-away-secret', 'ab'), PasswordFormat::DesCrypt];

        yield 'sha1 hex, lower case' => [sha1('a-throw-away-secret'), PasswordFormat::Sha1Hex];
        yield 'sha1 hex, upper case' => [strtoupper(sha1('a-throw-away-secret')), PasswordFormat::Sha1Hex];

        yield 'mysql PASSWORD()' => [
            '*' . strtoupper(sha1(sha1('a-throw-away-secret', true))),
            PasswordFormat::MysqlPassword,
        ];

        yield 'cleartext' => ['a-throw-away-secret', PasswordFormat::Plaintext];
        yield 'cleartext with punctuation' => ['Sommer 2026!', PasswordFormat::Plaintext];

        yield 'empty column' => ['', PasswordFormat::Unknown];
        yield 'argon2i from another tool' => ['$argon2i$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$abc', PasswordFormat::Unknown];
        yield 'yescrypt' => ['$y$j9T$abcdefghijklmnop$qrstuvwxyz', PasswordFormat::Unknown];
        yield 'dovecot scheme prefix' => ['{SSHA}abcdefghijklmnopqrstuvwxyz012345', PasswordFormat::Unknown];
    }

    /**
     * An empty column must never be read as "the password is the empty string".
     * Otherwise an account whose password was never set is open to anyone who
     * submits nothing.
     */
    public function testAnEmptyColumnIsUnknownAndNotCleartext(): void
    {
        self::assertSame(PasswordFormat::Unknown, PasswordFormatDetector::detect(''));
        self::assertNotSame(PasswordFormat::Plaintext, PasswordFormatDetector::detect(''));
    }

    /**
     * Documents ruling 2: thirteen characters from the crypt alphabet are read
     * as DES-crypt even when they happen to be somebody's cleartext password.
     *
     * The other reading would let anyone who can read the `accountuser` table
     * log in by typing the stored hash.
     */
    public function testThirteenPrintableCharactersAreReadAsDesCryptNotAsCleartext(): void
    {
        self::assertSame(PasswordFormat::DesCrypt, PasswordFormatDetector::detect('SommerZweiXYZ'));
        self::assertSame(13, strlen('SommerZweiXYZ'));

        // One character more, and the ambiguity is gone.
        self::assertSame(PasswordFormat::Plaintext, PasswordFormatDetector::detect('SommerZweiXYZ4'));

        // A character outside the crypt alphabet, likewise.
        self::assertSame(PasswordFormat::Plaintext, PasswordFormatDetector::detect('SommerZwei!YZ'));
    }

    public function testFortyHexCharactersAreReadAsSha1NotAsCleartext(): void
    {
        $fortyHex = str_repeat('abcd1234', 5);

        self::assertSame(40, strlen($fortyHex));
        self::assertSame(PasswordFormat::Sha1Hex, PasswordFormatDetector::detect($fortyHex));
    }

    /**
     * Everything the detector returns has to be answerable by the enum's own
     * classification — that is what U-22 shows in the account list.
     */
    #[DataProvider('hashes')]
    public function testTheRecognisedFormatAnswersTheClassificationQuestions(
        string $hash,
        PasswordFormat $expected,
    ): void {
        self::assertNotSame($expected->isCurrent(), $expected->isLegacy());
    }
}

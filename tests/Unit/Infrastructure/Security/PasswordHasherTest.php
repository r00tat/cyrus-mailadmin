<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Architecture 5.5, one test per row, positive and negative.
 *
 * **Every hash in this file is produced here, from a password invented here.**
 * Nothing is copied out of `docs/internal/` or out of the production database —
 * a real hash in a test file is a real hash in the repository.
 *
 * The cost parameters are deliberately the cheapest ones PHP allows: this suite
 * hashes a few dozen times and must stay fast enough that nobody is tempted to
 * skip it. `PasswordHashingOptionsTest` covers the production values.
 */
#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    private const string CORRECT = 'Untypischer-Sonnenschirm-42';

    /**
     * Differs from {@see CORRECT} in the **first** character on purpose.
     *
     * A wrong password that shares the first eight characters would verify
     * against the DES-crypt row — see
     * {@see testDesCryptIgnoresEverythingPastTheEighthCharacter()}, where that
     * is the point rather than an accident.
     */
    private const string WRONG = 'Wolkenbruch-Nachmittag-43';

    // ------------------------------------------------------------------
    // Verifying every legacy format (E-13: they all stay valid)
    // ------------------------------------------------------------------

    #[DataProvider('storedHashes')]
    public function testTheCorrectPasswordVerifies(string $storedHash, PasswordFormat $expected): void
    {
        self::assertSame($expected, $this->hasher()->detectFormat($storedHash));
        self::assertTrue($this->hasher()->verify(self::CORRECT, $storedHash));
    }

    #[DataProvider('storedHashes')]
    public function testAWrongPasswordDoesNotVerify(string $storedHash, PasswordFormat $expected): void
    {
        self::assertSame($expected, $this->hasher()->detectFormat($storedHash));
        self::assertFalse($this->hasher()->verify(self::WRONG, $storedHash));
    }

    /**
     * @return iterable<string, array{string, PasswordFormat}>
     */
    public static function storedHashes(): iterable
    {
        yield 'argon2id' => [
            password_hash(self::CORRECT, PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]),
            PasswordFormat::Argon2id,
        ];
        yield 'bcrypt' => [
            password_hash(self::CORRECT, PASSWORD_BCRYPT, ['cost' => 4]),
            PasswordFormat::Bcrypt,
        ];
        yield 'md5crypt' => [(string) crypt(self::CORRECT, '$1$Nq3zP1xK$'), PasswordFormat::Md5Crypt];
        yield 'sha256crypt' => [(string) crypt(self::CORRECT, '$5$Nq3zP1xK$'), PasswordFormat::Sha256Crypt];
        yield 'sha512crypt' => [(string) crypt(self::CORRECT, '$6$Nq3zP1xK$'), PasswordFormat::Sha512Crypt];
        yield 'des-crypt' => [(string) crypt(self::CORRECT, 'Nq'), PasswordFormat::DesCrypt];
        yield 'sha1 hex' => [sha1(self::CORRECT), PasswordFormat::Sha1Hex];
        yield 'mysql PASSWORD()' => [
            '*' . strtoupper(sha1(sha1(self::CORRECT, true))),
            PasswordFormat::MysqlPassword,
        ];
        yield 'cleartext' => [self::CORRECT, PasswordFormat::Plaintext];
    }

    /**
     * webcyradm wrote SHA1 in lower case, but a hand-edited row may be upper
     * case. Both have to verify.
     */
    public function testSha1IsVerifiedRegardlessOfHexCase(): void
    {
        self::assertTrue($this->hasher()->verify(self::CORRECT, strtoupper(sha1(self::CORRECT))));
        self::assertFalse($this->hasher()->verify(self::WRONG, strtoupper(sha1(self::CORRECT))));
    }

    /**
     * The reimplementation of MySQL's `PASSWORD()` — no database round trip, so
     * no cleartext password in the query log of a shared server.
     *
     * The expected value is the published vector for `password`, which every
     * MySQL 4.1 and later produces. If the implementation drifts, this fails
     * against an outside reference rather than against itself.
     */
    public function testMysqlPasswordMatchesThePublishedVector(): void
    {
        self::assertSame(
            '*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19',
            PasswordHasher::mysqlPassword('password'),
        );
    }

    public function testMysqlPasswordIsFortyOneCharactersStartingWithAnAsterisk(): void
    {
        $hash = PasswordHasher::mysqlPassword(self::CORRECT);

        self::assertSame(41, strlen($hash));
        self::assertStringStartsWith('*', $hash);
        self::assertSame($hash, strtoupper($hash));
    }

    /**
     * The finding behind S-2, kept as a test rather than as a footnote:
     * traditional DES crypt hashes only the first eight characters. Two
     * different passwords that share those eight verify against the same hash.
     *
     * These accounts stay valid on purpose (E-13). The account list shows the
     * format so the risk stays visible (U-22) instead of ageing unobserved.
     */
    public function testDesCryptIgnoresEverythingPastTheEighthCharacter(): void
    {
        $stored = (string) crypt('Sonnenschirm-A', 'Nq');

        self::assertTrue($this->hasher()->verify('Sonnenschirm-A', $stored));
        self::assertTrue(
            $this->hasher()->verify('Sonnensch', $stored),
            'DES crypt truncates at eight characters — that is the weakness, documented as a test.',
        );
        self::assertTrue($this->hasher()->verify('Sonnenschirm-B', $stored));

        // The first eight characters still have to match.
        self::assertFalse($this->hasher()->verify('Wolkenschirm-A', $stored));
    }

    // ------------------------------------------------------------------
    // What must never verify
    // ------------------------------------------------------------------

    public function testAnEmptyColumnNeverVerifiesNotEvenAgainstAnEmptyPassword(): void
    {
        self::assertFalse($this->hasher()->verify('', ''));
        self::assertFalse($this->hasher()->verify(self::CORRECT, ''));
    }

    #[DataProvider('unreadableHashes')]
    public function testAHashInNoRecognisedFormatNeverVerifies(string $storedHash): void
    {
        self::assertSame(PasswordFormat::Unknown, $this->hasher()->detectFormat($storedHash));
        self::assertFalse($this->hasher()->verify(self::CORRECT, $storedHash));
        self::assertFalse($this->hasher()->verify($storedHash, $storedHash));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableHashes(): iterable
    {
        yield 'empty' => [''];
        yield 'argon2i' => ['$argon2i$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$0000'];
        yield 'dovecot scheme' => ['{SSHA512}abcdefghijklmnopqrstuvwxyz'];
    }

    /**
     * Submitting the stored hash itself must never work. It is the reason a
     * 13-character crypt-alphabet value is read as DES and not as cleartext.
     */
    public function testSubmittingTheStoredHashDoesNotAuthenticate(): void
    {
        foreach (self::storedHashes() as $name => [$storedHash, $format]) {
            if ($format === PasswordFormat::Plaintext) {
                continue;
            }

            self::assertFalse(
                $this->hasher()->verify($storedHash, $storedHash),
                sprintf('Typing the stored %s hash authenticated the user.', $name),
            );
        }
    }

    // ------------------------------------------------------------------
    // Writing: the target format per table
    // ------------------------------------------------------------------

    public function testAnAccountPasswordIsWrittenAsBcrypt(): void
    {
        $hash = $this->hasher()->hash($this->password(PasswordSubject::AccountUser));

        self::assertStringStartsWith('$2y$', $hash);
        self::assertSame(PasswordFormat::Bcrypt, $this->hasher()->detectFormat($hash));
        self::assertTrue($this->hasher()->verify(self::CORRECT, $hash));
    }

    /**
     * The definition of done, as a unit test: what mailadmin writes into
     * `accountuser`, `crypt(3)` reads back — and `crypt(3)` is what `pam_mysql`
     * calls for every IMAP and SMTP login.
     */
    public function testAnAccountHashIsVerifiableByCryptItself(): void
    {
        $hash = $this->hasher()->hash($this->password(PasswordSubject::AccountUser));

        self::assertSame($hash, crypt(self::CORRECT, $hash));
        self::assertNotSame($hash, crypt(self::WRONG, $hash));
    }

    public function testAnAdminPasswordIsWrittenAsArgon2id(): void
    {
        $hash = $this->hasher()->hash($this->password(PasswordSubject::AdminUser));

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertSame(PasswordFormat::Argon2id, $this->hasher()->detectFormat($hash));
        self::assertTrue($this->hasher()->verify(self::CORRECT, $hash));
    }

    /**
     * The hash carries the configured cost, so a later change to the cost is
     * what `needsRehash()` sees.
     */
    public function testTheBcryptHashCarriesTheConfiguredCost(): void
    {
        $hasher = new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 5));

        self::assertStringStartsWith('$2y$05$', $hasher->hash($this->password(PasswordSubject::AccountUser)));
    }

    public function testTwoHashesOfTheSamePasswordDiffer(): void
    {
        $first = $this->hasher()->hash($this->password(PasswordSubject::AccountUser));
        $second = $this->hasher()->hash($this->password(PasswordSubject::AccountUser));

        self::assertNotSame($first, $second, 'Each hash must carry its own salt.');
        self::assertTrue($this->hasher()->verify(self::CORRECT, $first));
        self::assertTrue($this->hasher()->verify(self::CORRECT, $second));
    }

    // ------------------------------------------------------------------
    // Rehashing (E-13)
    // ------------------------------------------------------------------

    #[DataProvider('storedHashes')]
    public function testEveryLegacyFormatWantsARehashForBothSubjects(
        string $storedHash,
        PasswordFormat $format,
    ): void {
        foreach (PasswordSubject::cases() as $subject) {
            if ($format === $subject->targetFormat()) {
                continue;
            }

            self::assertTrue(
                $this->hasher()->needsRehash($storedHash, $subject),
                sprintf('%s in %s should be rewritten on the next successful login.', $format->value, $subject->table()),
            );
        }
    }

    #[DataProvider('subjects')]
    public function testAFreshHashInTheTargetFormatWantsNoRehash(PasswordSubject $subject): void
    {
        $hasher = $this->hasher();

        self::assertFalse($hasher->needsRehash($hasher->hash($this->password($subject)), $subject));
    }

    /**
     * The one that keeps IMAP working: an Argon2id hash is perfectly modern and
     * still wrong in `accountuser`, because `pam_mysql` cannot read it.
     */
    public function testAnArgon2idHashInTheAccountTableWantsARehashToBcrypt(): void
    {
        $hasher = $this->hasher();
        $adminHash = $hasher->hash($this->password(PasswordSubject::AdminUser));

        self::assertFalse($hasher->needsRehash($adminHash, PasswordSubject::AdminUser));
        self::assertTrue($hasher->needsRehash($adminHash, PasswordSubject::AccountUser));
    }

    public function testABcryptHashInTheAdminTableWantsARehashToArgon2id(): void
    {
        $hasher = $this->hasher();
        $accountHash = $hasher->hash($this->password(PasswordSubject::AccountUser));

        self::assertFalse($hasher->needsRehash($accountHash, PasswordSubject::AccountUser));
        self::assertTrue($hasher->needsRehash($accountHash, PasswordSubject::AdminUser));
    }

    public function testARaisedCostMakesAnExistingHashWantARehash(): void
    {
        $cheap = new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 4));
        $hash = $cheap->hash($this->password(PasswordSubject::AccountUser));

        self::assertFalse($cheap->needsRehash($hash, PasswordSubject::AccountUser));

        $expensive = new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 6));

        self::assertTrue($expensive->needsRehash($hash, PasswordSubject::AccountUser));
    }

    public function testAnUnreadableHashWantsARehash(): void
    {
        foreach (PasswordSubject::cases() as $subject) {
            self::assertTrue($this->hasher()->needsRehash('{SSHA}abcdef', $subject));
            self::assertTrue($this->hasher()->needsRehash('', $subject));
        }
    }

    /**
     * Cleartext is the case S1's open question 6 asks about: it needs rewriting
     * the first time it verifies, not eventually. `needsRehash()` already says
     * so, so the ordinary path covers it — no special branch is needed.
     */
    public function testCleartextWantsARehashOnTheFirstSuccessfulVerification(): void
    {
        $hasher = $this->hasher();

        self::assertTrue($hasher->verify(self::CORRECT, self::CORRECT));
        self::assertTrue($hasher->needsRehash(self::CORRECT, PasswordSubject::AccountUser));
    }

    // ------------------------------------------------------------------
    // The unknown user name
    // ------------------------------------------------------------------

    #[DataProvider('subjects')]
    public function testTheDummyVerificationAlwaysFails(PasswordSubject $subject): void
    {
        $hasher = $this->hasher();

        self::assertFalse($hasher->verifyDummy(self::CORRECT, $subject));
        self::assertFalse($hasher->verifyDummy('', $subject));
        self::assertFalse($hasher->verifyDummy(str_repeat('x', 200), $subject));
    }

    // ------------------------------------------------------------------
    // No logging (definition of done)
    // ------------------------------------------------------------------

    /**
     * U-28 can mask a context field by name; it cannot look inside a string. So
     * the guarantee has to be that these classes never write a line at all.
     */
    #[DataProvider('sourceFiles')]
    public function testNoClassInThisPackageTouchesALogger(string $file): void
    {
        $source = (string) file_get_contents($file);

        self::assertStringNotContainsString('Psr\\Log', $source, basename($file) . ' pulls in a logger.');
        self::assertStringNotContainsString('error_log', $source);
        self::assertStringNotContainsString('LoggerInterface', $source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sourceFiles(): iterable
    {
        $roots = [
            dirname(__DIR__, 4) . '/src/Domain/Password',
            dirname(__DIR__, 4) . '/src/Infrastructure/Security',
        ];

        foreach ($roots as $root) {
            foreach ((array) glob($root . '/*.php') as $file) {
                if (!is_string($file)) {
                    continue;
                }

                yield basename(dirname($file)) . '/' . basename($file) => [$file];
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{PasswordSubject}>
     */
    public static function subjects(): iterable
    {
        foreach (PasswordSubject::cases() as $subject) {
            yield $subject->value => [$subject];
        }
    }

    private function hasher(): PasswordHasher
    {
        return new PasswordHasher(PasswordHashingOptions::of(
            bcryptCost: 4,
            argon2MemoryKib: 8192,
            argon2TimeCost: 1,
            argon2Threads: 1,
        ));
    }

    private function password(PasswordSubject $subject): Password
    {
        return (new PasswordPolicy(new InMemoryCommonPasswordList()))->accept(self::CORRECT, $subject);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingFailed;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/**
 * The safety net inside `PasswordHasher::hash()`, exercised directly.
 *
 * By construction nothing can reach it: the format comes from a two-case
 * `match` over {@see PasswordSubject}, so the produced hash is always the
 * target one. The net exists for the edit that breaks that — a third subject, a
 * changed branch, a PHP build where `PASSWORD_BCRYPT` no longer means bcrypt.
 * A net nobody has ever pulled is a net nobody knows holds, so these tests pull
 * it with reflection.
 *
 * The rule being protected: a hash that `crypt(3)` cannot verify must never be
 * returned for `accountuser`, because `pam_mysql` verifies that column and
 * every IMAP and SMTP login depends on it (`CLAUDE.md`, E-3).
 */
#[CoversClass(PasswordHasher::class)]
#[CoversClass(PasswordHashingFailed::class)]
final class PasswordHasherGuardTest extends TestCase
{
    private const string PLAINTEXT = 'Untypischer-Sonnenschirm-42';

    public function testAnArgon2idHashIsRefusedForTheAccountTable(): void
    {
        $refusal = $this->guardRefusal(
            (string) password_hash(self::PLAINTEXT, PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1]),
            PasswordSubject::AccountUser,
            PasswordFormat::Bcrypt,
        );

        self::assertStringContainsString('accountuser', $refusal->getMessage());
        self::assertStringContainsString('argon2id', $refusal->getMessage());
    }

    public function testASha512CryptHashIsRefusedForTheAccountTable(): void
    {
        // crypt(3) could read this one — it is still not the target format, and
        // a column that drifts between formats is a column nobody can reason
        // about.
        $refusal = $this->guardRefusal(
            (string) crypt(self::PLAINTEXT, '$6$Nq3zP1xK$'),
            PasswordSubject::AccountUser,
            PasswordFormat::Bcrypt,
        );

        self::assertStringContainsString('sha512crypt', $refusal->getMessage());
        self::assertStringContainsString('bcrypt', $refusal->getMessage());
    }

    public function testABcryptHashIsRefusedForTheAdminTable(): void
    {
        $refusal = $this->guardRefusal(
            (string) password_hash(self::PLAINTEXT, PASSWORD_BCRYPT, ['cost' => 4]),
            PasswordSubject::AdminUser,
            PasswordFormat::Argon2id,
        );

        self::assertStringContainsString('adminuser', $refusal->getMessage());
        self::assertStringContainsString('argon2id', $refusal->getMessage());
    }

    /**
     * The round-trip check: the hash is bcrypt and in the target format, but it
     * is not a hash *of this password*. `crypt(3)` says so, and so does the
     * guard — which is the same question `pam_mysql` will ask later.
     */
    public function testAHashThatCryptDoesNotConfirmIsRefused(): void
    {
        $refusal = $this->guardRefusal(
            (string) password_hash('an-entirely-different-secret', PASSWORD_BCRYPT, ['cost' => 4]),
            PasswordSubject::AccountUser,
            PasswordFormat::Bcrypt,
        );

        self::assertStringContainsString('crypt(3)', $refusal->getMessage());
        self::assertStringContainsString('Nothing was stored', $refusal->getMessage());
    }

    public function testACorrectHashPassesTheGuardUnchanged(): void
    {
        $hash = (string) password_hash(self::PLAINTEXT, PASSWORD_BCRYPT, ['cost' => 4]);

        self::assertSame($hash, $this->guard($hash, PasswordSubject::AccountUser, PasswordFormat::Bcrypt));
    }

    /**
     * No refusal may quote the password or the hash: U-28's masking processor
     * cannot look inside a string, and these messages go to the log.
     */
    public function testNoRefusalMessageCarriesThePasswordOrTheHash(): void
    {
        $hash = (string) password_hash('an-entirely-different-secret', PASSWORD_BCRYPT, ['cost' => 4]);

        $refusal = $this->guardRefusal($hash, PasswordSubject::AccountUser, PasswordFormat::Bcrypt);

        self::assertStringNotContainsString(self::PLAINTEXT, $refusal->getMessage());
        self::assertStringNotContainsString($hash, $refusal->getMessage());
        self::assertStringNotContainsString(substr($hash, 7, 20), $refusal->getMessage());
    }

    private function guardRefusal(
        string $hash,
        PasswordSubject $subject,
        PasswordFormat $target,
    ): PasswordHashingFailed {
        try {
            $this->guard($hash, $subject, $target);
        } catch (PasswordHashingFailed $refusal) {
            return $refusal;
        } catch (Throwable $other) {
            self::fail('Expected PasswordHashingFailed, got ' . $other::class);
        }

        self::fail(sprintf(
            'The guard returned a hash that is not %s for %s. That hash would have been stored.',
            $target->value,
            $subject->table(),
        ));
    }

    private function guard(string $hash, PasswordSubject $subject, PasswordFormat $target): string
    {
        $hasher = new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 4));
        $method = new ReflectionMethod($hasher, 'guard');

        $result = $method->invoke($hasher, $hash, self::PLAINTEXT, $subject, $target);

        self::assertIsString($result);

        return $result;
    }
}

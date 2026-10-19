<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Config\Config;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordHashingOptions::class)]
final class PasswordHashingOptionsTest extends TestCase
{
    public function testTheDefaultsAreTheDocumentedOnes(): void
    {
        $options = PasswordHashingOptions::defaults();

        self::assertSame(10, $options->bcryptCost());
        self::assertSame(['cost' => 10], $options->bcryptOptions());
        self::assertSame(
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
            $options->argon2Options(),
        );
    }

    public function testTheEnvironmentOverridesTheDefaults(): void
    {
        $options = PasswordHashingOptions::fromConfig(Config::fromArray([
            PasswordHashingOptions::BCRYPT_COST => '12',
            PasswordHashingOptions::ARGON2_MEMORY_KIB => '131072',
            PasswordHashingOptions::ARGON2_TIME_COST => '6',
            PasswordHashingOptions::ARGON2_THREADS => '2',
        ]));

        self::assertSame(12, $options->bcryptCost());
        self::assertSame(
            ['memory_cost' => 131072, 'time_cost' => 6, 'threads' => 2],
            $options->argon2Options(),
        );
    }

    public function testAnEmptyEnvironmentYieldsTheDefaults(): void
    {
        self::assertEquals(
            PasswordHashingOptions::defaults(),
            PasswordHashingOptions::fromConfig(Config::fromArray([])),
        );
    }

    /**
     * A deployment may raise the cost. It may not lower it below what PHP
     * itself considers the minimum for bcrypt — that would be a downgrade
     * disguised as a tuning parameter, and nothing else would notice.
     */
    public function testTheCostCannotBeTunedBelowTheFloor(): void
    {
        $options = PasswordHashingOptions::fromConfig(Config::fromArray([
            PasswordHashingOptions::BCRYPT_COST => '4',
            PasswordHashingOptions::ARGON2_MEMORY_KIB => '1024',
            PasswordHashingOptions::ARGON2_TIME_COST => '1',
        ]));

        self::assertSame(PasswordHashingOptions::MINIMUM_BCRYPT_COST, $options->bcryptCost());
        self::assertSame(
            PasswordHashingOptions::MINIMUM_ARGON2_MEMORY_KIB,
            $options->argon2Options()['memory_cost'],
        );
        self::assertSame(
            PasswordHashingOptions::MINIMUM_ARGON2_TIME_COST,
            $options->argon2Options()['time_cost'],
        );
    }

    /**
     * A cost so high that an IMAP login takes seconds is a denial of service
     * against the mail server, not extra safety — `pam_mysql` pays it on every
     * fetch.
     */
    public function testTheCostIsCappedAtTheTop(): void
    {
        $options = PasswordHashingOptions::fromConfig(Config::fromArray([
            PasswordHashingOptions::BCRYPT_COST => '31',
            PasswordHashingOptions::ARGON2_THREADS => '512',
        ]));

        self::assertSame(PasswordHashingOptions::MAXIMUM_BCRYPT_COST, $options->bcryptCost());
        self::assertSame(
            PasswordHashingOptions::MAXIMUM_ARGON2_THREADS,
            $options->argon2Options()['threads'],
        );
    }

    /**
     * Nothing in this class names an algorithm. That is the point: no
     * environment variable can turn `accountuser` into Argon2id.
     */
    public function testThereIsNoAlgorithmSetting(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Infrastructure/Security/PasswordHashingOptions.php',
        );

        // No hashing call and no algorithm constant: this class carries costs.
        self::assertStringNotContainsString('password_hash', $source);
        self::assertStringNotContainsString('PASSWORD_BCRYPT,', $source);
        self::assertStringNotContainsString('PASSWORD_ARGON2ID', $source);

        // And no reader for one, either — the four keys below are the whole
        // surface, and none of them selects a scheme.
        foreach (get_class_methods(PasswordHashingOptions::class) as $method) {
            self::assertStringNotContainsStringIgnoringCase('algorithm', $method);
        }
    }

    /**
     * Every variable this class reads is namespaced, so nothing collides with
     * another package's configuration.
     */
    public function testEveryEnvironmentKeyIsNamespaced(): void
    {
        foreach ([
            PasswordHashingOptions::BCRYPT_COST,
            PasswordHashingOptions::ARGON2_MEMORY_KIB,
            PasswordHashingOptions::ARGON2_TIME_COST,
            PasswordHashingOptions::ARGON2_THREADS,
        ] as $key) {
            self::assertStringStartsWith('MAILADMIN_PASSWORD_', $key);
        }
    }
}

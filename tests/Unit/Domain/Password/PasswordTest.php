<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Password;

use LogicException;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stringable;

/**
 * The wrapper around a cleartext secret: it cannot exist unvalidated, and it
 * does what it can not to leak.
 */
#[CoversClass(Password::class)]
final class PasswordTest extends TestCase
{
    private const string PLAINTEXT = 'Untypischer-Sonnenschirm-42';

    public function testThereIsNoPublicConstructor(): void
    {
        $constructor = (new ReflectionClass(Password::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue(
            $constructor->isPrivate(),
            'A Password must be reachable only through PasswordPolicy, so that an unvalidated '
            . 'one cannot exist (U-21).',
        );
    }

    public function testItCarriesItsSubjectAndTheResultingTargetFormat(): void
    {
        self::assertSame(PasswordFormat::Bcrypt, $this->password(PasswordSubject::AccountUser)->targetFormat());
        self::assertSame(PasswordFormat::Argon2id, $this->password(PasswordSubject::AdminUser)->targetFormat());
    }

    public function testItReportsBothLengths(): void
    {
        $password = $this->password();

        self::assertSame(27, $password->length());
        self::assertSame(27, $password->lengthInBytes());
    }

    /**
     * String interpolation of a secret is the classic way one reaches a log
     * line. Without `__toString()` it is a fatal error instead — loud, and
     * caught by the first test run rather than by a log review.
     */
    public function testItCannotBeCastToAString(): void
    {
        self::assertFalse(
            (new ReflectionClass(Password::class))->hasMethod('__toString'),
            'Password must not be stringable: "{$password}" would put the secret wherever the '
            . 'string goes.',
        );
        self::assertNotInstanceOf(Stringable::class, $this->password());
    }

    public function testDebugOutputIsRedacted(): void
    {
        $dumped = print_r($this->password(), true);

        self::assertStringNotContainsString(self::PLAINTEXT, $dumped);
        self::assertStringContainsString('[redacted]', $dumped);
    }

    public function testItRefusesToBeSerialised(): void
    {
        $this->expectException(LogicException::class);

        serialize($this->password());
    }

    public function testRevealIsTheOnlyWayToTheSecret(): void
    {
        self::assertSame(self::PLAINTEXT, $this->password()->reveal());
    }

    private function password(PasswordSubject $subject = PasswordSubject::AccountUser): Password
    {
        return (new PasswordPolicy(new InMemoryCommonPasswordList()))->accept(self::PLAINTEXT, $subject);
    }
}

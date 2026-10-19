<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Password;

use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The invariant this whole package exists to protect.
 *
 * If `accountuser` ever gets a target format `crypt(3)` cannot read, IMAP and
 * SMTP AUTH stop working for every end user and nothing in mailadmin notices.
 * The check below is written over *every* case of the enum, so it also covers a
 * third subject somebody adds later.
 */
#[CoversClass(PasswordSubject::class)]
final class PasswordSubjectTest extends TestCase
{
    #[DataProvider('subjects')]
    public function testASubjectWithAnExternalConsumerHasACryptReadableTargetFormat(
        PasswordSubject $subject,
    ): void {
        if (!$subject->requiresCryptCompatibility()) {
            self::assertTrue(true, 'No external consumer, nothing to hold.');

            return;
        }

        self::assertTrue(
            $subject->targetFormat()->isCryptCompatible(),
            sprintf(
                'The target format for %s is %s, which crypt(3) cannot verify. pam_mysql reads '
                . 'this column — this would break IMAP and SMTP AUTH for every user. '
                . 'Changing it is an escalation case, see docs/04-detailkonzept.md section 5.',
                $subject->table(),
                $subject->targetFormat()->value,
            ),
        );
    }

    #[DataProvider('subjects')]
    public function testEveryTargetFormatIsOneOfTheTwoCurrentOnes(PasswordSubject $subject): void
    {
        self::assertTrue($subject->targetFormat()->isCurrent());
        self::assertFalse($subject->targetFormat()->isWeak());
    }

    public function testTheTargetFormatsAreTheOnesTheDecisionNames(): void
    {
        self::assertSame(PasswordFormat::Argon2id, PasswordSubject::AdminUser->targetFormat());
        self::assertSame(PasswordFormat::Bcrypt, PasswordSubject::AccountUser->targetFormat());
    }

    public function testOnlyTheAccountTableHasAnExternalConsumer(): void
    {
        self::assertTrue(PasswordSubject::AccountUser->requiresCryptCompatibility());
        self::assertFalse(PasswordSubject::AdminUser->requiresCryptCompatibility());
    }

    public function testTheMinimumLengthsAreTheOnesFromDecisionU21(): void
    {
        self::assertSame(12, PasswordSubject::AdminUser->minimumLength());
        self::assertSame(10, PasswordSubject::AccountUser->minimumLength());
    }

    public function testOnlyTheBcryptSubjectCarriesTheSeventyTwoByteLimit(): void
    {
        self::assertSame(Password::BCRYPT_INPUT_LIMIT, PasswordSubject::AccountUser->maximumLengthInBytes());
        self::assertNull(PasswordSubject::AdminUser->maximumLengthInBytes());
    }

    public function testTheValueIsTheTableName(): void
    {
        self::assertSame('adminuser', PasswordSubject::AdminUser->table());
        self::assertSame('accountuser', PasswordSubject::AccountUser->table());
    }

    /**
     * @return iterable<string, array{PasswordSubject}>
     */
    public static function subjects(): iterable
    {
        foreach (PasswordSubject::cases() as $subject) {
            yield $subject->value => [$subject];
        }
    }
}

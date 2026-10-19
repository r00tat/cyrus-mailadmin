<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Password;

use Mailadmin\Domain\Password\CommonPasswordList;
use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordErrorCode;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Password\PersonalTerms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * U-21, rule by rule.
 *
 * Every password in this file is invented for the test. None of them is, or
 * resembles, anything from the production system.
 */
#[CoversClass(PasswordPolicy::class)]
#[CoversClass(Password::class)]
#[CoversClass(InvalidPassword::class)]
#[CoversClass(InMemoryCommonPasswordList::class)]
final class PasswordPolicyTest extends TestCase
{
    private const string ACCEPTABLE = 'Untypischer-Sonnenschirm-42';

    public function testAnAcceptablePasswordBecomesAPasswordObject(): void
    {
        $password = $this->policy()->accept(self::ACCEPTABLE, PasswordSubject::AccountUser);

        self::assertSame(self::ACCEPTABLE, $password->reveal());
        self::assertSame(PasswordSubject::AccountUser, $password->subject());
    }

    // ------------------------------------------------------------------
    // Length
    // ------------------------------------------------------------------

    #[DataProvider('subjectsAndMinimumLengths')]
    public function testAPasswordAtTheMinimumLengthIsAcceptedAndOneBelowIsNot(
        PasswordSubject $subject,
        int $minimum,
    ): void {
        $atTheLimit = str_repeat('u', $minimum);

        self::assertTrue($this->policy()->isAcceptable($atTheLimit, $subject));

        $this->assertRefusedWith(
            PasswordErrorCode::PASSWORD_TOO_SHORT,
            fn () => $this->policy()->assertAcceptable(substr($atTheLimit, 1), $subject),
        );
    }

    /**
     * @return iterable<string, array{PasswordSubject, int}>
     */
    public static function subjectsAndMinimumLengths(): iterable
    {
        yield 'adminuser needs twelve' => [PasswordSubject::AdminUser, 12];
        yield 'accountuser needs ten' => [PasswordSubject::AccountUser, 10];
    }

    /**
     * The minimum counts characters, not bytes — otherwise a password made of
     * umlauts would pass a byte count it should not.
     */
    public function testTheMinimumLengthCountsCharactersNotBytes(): void
    {
        $tenCharacters = 'öäüöäüöäüö';

        self::assertSame(20, strlen($tenCharacters), 'Two bytes per character in UTF-8.');
        self::assertSame(10, PasswordPolicy::characterLength($tenCharacters));

        self::assertTrue($this->policy()->isAcceptable($tenCharacters, PasswordSubject::AccountUser));
        self::assertFalse($this->policy()->isAcceptable(substr($tenCharacters, 0, 18), PasswordSubject::AccountUser));
    }

    // ------------------------------------------------------------------
    // The 72-byte limit — the rule that must reject rather than truncate
    // ------------------------------------------------------------------

    public function testAnAccountPasswordOfExactlySeventyTwoBytesIsAccepted(): void
    {
        $atTheLimit = str_repeat('a', Password::BCRYPT_INPUT_LIMIT);

        $password = $this->policy()->accept($atTheLimit, PasswordSubject::AccountUser);

        self::assertSame(Password::BCRYPT_INPUT_LIMIT, $password->lengthInBytes());
    }

    public function testAnAccountPasswordOverSeventyTwoBytesIsRejectedAndNotShortened(): void
    {
        $tooLong = str_repeat('a', Password::BCRYPT_INPUT_LIMIT + 1);

        try {
            $this->policy()->accept($tooLong, PasswordSubject::AccountUser);
            self::fail('bcrypt would silently ignore the excess; U-21 requires a refusal.');
        } catch (InvalidPassword $refused) {
            self::assertSame(PasswordErrorCode::PASSWORD_TOO_LONG, $refused->code());
            self::assertSame(73, $refused->context()['length_bytes']);
            self::assertSame(72, $refused->context()['maximum_length_bytes']);
        }
    }

    /**
     * The limit is in bytes because bcrypt's is. Twenty-five umlauts are 50
     * bytes and pass; forty are 80 bytes and do not, even though 40 characters
     * looks harmless.
     */
    public function testTheSeventyTwoByteLimitIsCountedInBytes(): void
    {
        self::assertTrue($this->policy()->isAcceptable(str_repeat('ö', 25), PasswordSubject::AccountUser));
        self::assertFalse($this->policy()->isAcceptable(str_repeat('ö', 40), PasswordSubject::AccountUser));
    }

    /**
     * Argon2id has no 72-byte limit, so `adminuser` keeps only the absolute cap.
     */
    public function testAnAdminPasswordMayExceedSeventyTwoBytes(): void
    {
        self::assertTrue($this->policy()->isAcceptable(
            str_repeat('a', Password::BCRYPT_INPUT_LIMIT + 20),
            PasswordSubject::AdminUser,
        ));
    }

    public function testTheAbsoluteCapAppliesToEverySubject(): void
    {
        foreach (PasswordSubject::cases() as $subject) {
            self::assertFalse($this->policy()->isAcceptable(
                str_repeat('a', Password::ABSOLUTE_LIMIT + 1),
                $subject,
            ));
        }
    }

    // ------------------------------------------------------------------
    // Block list, personal data, characters
    // ------------------------------------------------------------------

    public function testACommonPasswordIsRefused(): void
    {
        $this->assertRefusedWith(
            PasswordErrorCode::PASSWORD_TOO_COMMON,
            fn () => $this->policy(['trombone-picture'])
                ->assertAcceptable('trombone-picture', PasswordSubject::AccountUser),
        );
    }

    public function testTheBlockListIsCaseInsensitive(): void
    {
        self::assertFalse(
            $this->policy(['trombone-picture'])->isAcceptable('Trombone-Picture', PasswordSubject::AccountUser),
        );
    }

    public function testAPasswordContainingTheLocalPartIsRefused(): void
    {
        try {
            $this->policy()->assertAcceptable(
                'Mustermann-2026-abc',
                PasswordSubject::AccountUser,
                PersonalTerms::of('greta.mustermann@example.test'),
            );
            self::fail('Expected a refusal.');
        } catch (InvalidPassword $refused) {
            self::assertSame(PasswordErrorCode::PASSWORD_CONTAINS_PERSONAL_DATA, $refused->code());
            self::assertSame('mustermann', $refused->context()['term']);
        }
    }

    public function testAPasswordContainingTheDomainIsRefused(): void
    {
        self::assertFalse($this->policy()->isAcceptable(
            'wolkig-example-schirm',
            PasswordSubject::AccountUser,
            PersonalTerms::of('info@example.test'),
        ));
    }

    public function testWithoutPersonalTermsNothingIsCheckedAgainst(): void
    {
        self::assertTrue($this->policy()->isAcceptable('Mustermann-2026-abc', PasswordSubject::AccountUser));
    }

    public function testAnEmptyPasswordIsRefusedWithItsOwnCode(): void
    {
        $this->assertRefusedWith(
            PasswordErrorCode::PASSWORD_EMPTY,
            fn () => $this->policy()->assertAcceptable('', PasswordSubject::AccountUser),
        );
    }

    /**
     * A NUL byte would truncate the secret inside `crypt(3)` and `pam_mysql`,
     * so the password protecting the mailbox would be shorter than the one
     * entered — and shorter than the one this policy measured.
     */
    public function testAPasswordWithAControlCharacterIsRefused(): void
    {
        $this->assertRefusedWith(
            PasswordErrorCode::PASSWORD_INVALID_CHARACTERS,
            fn () => $this->policy()->assertAcceptable("Sonnenschirm\0nachher", PasswordSubject::AccountUser),
        );
    }

    public function testAPasswordThatIsNotValidUtf8IsRefused(): void
    {
        $this->assertRefusedWith(
            PasswordErrorCode::PASSWORD_INVALID_CHARACTERS,
            fn () => $this->policy()->assertAcceptable("Sonnenschirm\xC3\x28", PasswordSubject::AccountUser),
        );
    }

    /**
     * No character-class requirement (U-21, following NIST SP 800-63B): a long
     * lower-case passphrase is fine and a space is not a reason to refuse.
     */
    #[DataProvider('acceptedShapes')]
    public function testThereIsNoCharacterClassRequirement(string $plaintext): void
    {
        self::assertTrue($this->policy()->isAcceptable($plaintext, PasswordSubject::AccountUser));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedShapes(): iterable
    {
        yield 'lower case only' => ['bergsteigenimregen'];
        yield 'digits only' => ['4830295718264'];
        yield 'with spaces' => ['drei wolken ziehen'];
        yield 'emoji' => ['Schirm-🌧-Wolke-42'];
        yield 'cyrillic' => ['падаетдождь77'];
    }

    // ------------------------------------------------------------------
    // U-28: no refusal may carry the secret
    // ------------------------------------------------------------------

    /**
     * The masking processor cannot look inside a string (U-28), so a password
     * in an exception message is a password in the log file.
     */
    #[DataProvider('refusals')]
    public function testNoMessageOrContextEverCarriesThePassword(
        string $plaintext,
        PasswordSubject $subject,
        ?PersonalTerms $terms,
    ): void {
        try {
            $this->policy(['sonnenaufgangimmai'])->assertAcceptable($plaintext, $subject, $terms);
            self::fail('This input was supposed to be refused.');
        } catch (InvalidPassword $refused) {
            $serialisedContext = implode(' ', array_map(
                static fn (string|int $value): string => (string) $value,
                $refused->context(),
            ));

            self::assertStringNotContainsString($plaintext, $refused->getMessage());
            self::assertStringNotContainsString($plaintext, $serialisedContext);
        }
    }

    /**
     * @return iterable<string, array{string, PasswordSubject, PersonalTerms|null}>
     */
    public static function refusals(): iterable
    {
        yield 'too short' => ['kurz', PasswordSubject::AccountUser, null];
        yield 'too long' => [str_repeat('l', 100), PasswordSubject::AccountUser, null];
        yield 'over the absolute cap' => [str_repeat('l', 5000), PasswordSubject::AdminUser, null];
        yield 'on the block list' => ['sonnenaufgangimmai', PasswordSubject::AccountUser, null];
        yield 'control character' => ["kontrollzeichen\x01hier", PasswordSubject::AccountUser, null];
        yield 'invalid utf-8' => ["ungueltigesbyte\xFFhier", PasswordSubject::AccountUser, null];
        yield 'contains the local part' => [
            'mustermann-wolke-42',
            PasswordSubject::AccountUser,
            PersonalTerms::of('greta.mustermann@example.test'),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<string> $blocked
     */
    private function policy(array $blocked = []): PasswordPolicy
    {
        return new PasswordPolicy($this->list($blocked));
    }

    /**
     * @param list<string> $blocked
     */
    private function list(array $blocked): CommonPasswordList
    {
        return new InMemoryCommonPasswordList($blocked);
    }

    /**
     * Asserts the stable error code, not the message: the code is what the
     * contract carries and what the frontend translates (U-7, E-16).
     *
     * @param callable(): mixed $call
     */
    private function assertRefusedWith(string $code, callable $call): void
    {
        try {
            $call();
        } catch (InvalidPassword $refused) {
            self::assertSame($code, $refused->code());

            return;
        }

        self::fail(sprintf('Expected the password to be refused with "%s".', $code));
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Model\Account;
use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Model\ReservedLocalPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Account::class)]
#[CoversClass(AccountServices::class)]
#[CoversClass(PasswordFormat::class)]
#[CoversClass(AdminRole::class)]
#[CoversClass(ReservedLocalPart::class)]
final class AccountTest extends TestCase
{
    public function testTheIdIsTheStoredCyrusUserName(): void
    {
        // U-18: there is no second, numeric identity for an account.
        $account = self::account(NamingScheme::Dotted);

        self::assertSame('greta.mustermann.example.test', $account->id());
        self::assertSame('greta.mustermann@example.test', $account->address()->toString());
        self::assertSame('example.test', $account->domain()->toString());
        self::assertSame('greta.mustermann', $account->localPart()->toString());
    }

    public function testTheSchemeComesFromTheIdentityAndNotFromConfiguration(): void
    {
        $account = self::account(NamingScheme::Fqun);

        self::assertSame(NamingScheme::Fqun, $account->namingScheme());
        // U-22: the deviation from the domain default is shown, not repaired.
        self::assertTrue($account->divergesFromDomainScheme(NamingScheme::Dotted));
        self::assertFalse($account->divergesFromDomainScheme(NamingScheme::Fqun));
    }

    public function testTheRecognisedSchemeSurvivesAReadFromTheDatabase(): void
    {
        $account = new Account(
            MailboxIdentity::fromStoredUsername(
                'greta.mustermann@example.test',
                DomainName::fromString('example.test'),
            ),
            AccountServices::all(),
        );

        self::assertSame(NamingScheme::Fqun, $account->namingScheme());
        self::assertSame('greta.mustermann@example.test', $account->id());
    }

    public function testThePasswordFormatIsCarriedForDisplay(): void
    {
        $account = self::account(NamingScheme::Dotted)
            ->withPasswordFormat(PasswordFormat::DesCrypt);

        self::assertSame(PasswordFormat::DesCrypt, $account->passwordFormat);
        self::assertFalse($account->hasCurrentPasswordFormat());
        self::assertTrue($account->withPasswordFormat(PasswordFormat::Bcrypt)
            ->hasCurrentPasswordFormat());
    }

    public function testAnUnsetPasswordFormatIsUnknownRatherThanAssumed(): void
    {
        self::assertSame(PasswordFormat::Unknown, self::account(NamingScheme::Dotted)
            ->passwordFormat);
    }

    public function testOnlyBcryptAndArgonAreCurrentAndOnlyCryptFormatsWorkForPamMysql(): void
    {
        self::assertTrue(PasswordFormat::Bcrypt->isCurrent());
        self::assertTrue(PasswordFormat::Argon2id->isCurrent());
        self::assertTrue(PasswordFormat::DesCrypt->isLegacy());

        // The rule that must never be got wrong for accountuser.
        self::assertTrue(PasswordFormat::Bcrypt->isCryptCompatible());
        self::assertFalse(PasswordFormat::Argon2id->isCryptCompatible());
        self::assertFalse(PasswordFormat::Sha1Hex->isCryptCompatible());
        self::assertTrue(PasswordFormat::Sha512Crypt->isCryptCompatible());

        self::assertTrue(PasswordFormat::Plaintext->isWeak());
        self::assertFalse(PasswordFormat::Bcrypt->isWeak());
    }

    public function testServiceFlagsAreReadAndWrittenInTheLegacyShape(): void
    {
        $services = AccountServices::fromLegacyFlags(1, 0, 1, 2);

        self::assertTrue($services->imap);
        self::assertFalse($services->pop);
        self::assertTrue($services->sieve);
        // Anything other than 0 lets the user in — pretending otherwise would
        // misdescribe the running system.
        self::assertTrue($services->smtpAuth);
        self::assertSame(
            ['imap' => 1, 'pop' => 0, 'sieve' => 1, 'smtpauth' => 1],
            $services->toLegacyFlags(),
        );
    }

    public function testAnAccountWithEveryServiceOffHasNoAccessLeft(): void
    {
        self::assertFalse(AccountServices::none()->allowsAnyAccess());
        self::assertTrue(AccountServices::all()->allowsAnyAccess());

        $account = self::account(NamingScheme::Dotted)->withServices(AccountServices::none());
        self::assertFalse($account->services->allowsAnyAccess());
    }

    public function testChangingTheIdentityProducesTheTargetOfARename(): void
    {
        $account = self::account(NamingScheme::Dotted);
        $moved = $account->withIdentity($account->identity->withScheme(NamingScheme::Fqun));

        self::assertSame('greta.mustermann.example.test', $account->id());
        self::assertSame('greta.mustermann@example.test', $moved->id());
        self::assertFalse($account->equals($moved));
    }

    public function testTheLegacyAdminTypeMapsOntoTheTwoRoles(): void
    {
        self::assertSame(AdminRole::Superuser, AdminRole::fromLegacyType(0));
        self::assertSame(AdminRole::DomainMaster, AdminRole::fromLegacyType(1));
        self::assertSame(0, AdminRole::Superuser->toLegacyType());
        self::assertSame(1, AdminRole::DomainMaster->toLegacyType());
        self::assertTrue(AdminRole::Superuser->isSuperuser());
        self::assertSame('*', AdminRole::ALL_DOMAINS);
    }

    public function testTheReservedNamesAreStatedButNotEnforced(): void
    {
        self::assertTrue(ReservedLocalPart::isReserved(LocalPart::fromString('Postmaster')));
        self::assertTrue(ReservedLocalPart::isMandatory(LocalPart::fromString('abuse')));
        self::assertFalse(ReservedLocalPart::isMandatory(LocalPart::fromString('root')));
        self::assertFalse(ReservedLocalPart::isReserved(LocalPart::fromString('info')));
    }

    private static function account(NamingScheme $scheme): Account
    {
        return new Account(
            MailboxIdentity::create(
                LocalPart::fromString('greta.mustermann'),
                DomainName::fromString('example.test'),
                $scheme,
            ),
            AccountServices::all(),
        );
    }
}

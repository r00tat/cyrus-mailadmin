<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Exception\InvalidDomainConfiguration;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AccountLimit;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\NamingScheme;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Domain::class)]
#[CoversClass(AccountLimit::class)]
#[CoversClass(DefaultFolders::class)]
#[CoversClass(InvalidDomainConfiguration::class)]
final class DomainTest extends TestCase
{
    public function testTheNamingSchemeForNewAccountsFollowsTheThreeSteps(): void
    {
        $withSetting = self::domain(NamingScheme::Fqun);
        $withoutSetting = self::domain(null);

        self::assertSame(
            NamingScheme::Fqun,
            $withSetting->namingSchemeForNewAccounts(NamingScheme::Dotted),
        );
        self::assertSame(
            NamingScheme::Fqun,
            $withoutSetting->namingSchemeForNewAccounts(NamingScheme::Fqun),
        );
        self::assertSame(NamingScheme::Dotted, $withoutSetting->namingSchemeForNewAccounts());
    }

    public function testAnIdentityForANewAccountUsesThatScheme(): void
    {
        $identity = self::domain(NamingScheme::Fqun)
            ->identityFor(LocalPart::fromString('greta.mustermann'));

        self::assertSame('greta.mustermann@example.test', $identity->cyrusUsername());
    }

    public function testTheLoginRealmHintFollowsTheScheme(): void
    {
        self::assertTrue(self::domain(NamingScheme::Fqun)->needsLoginRealmHint());
        self::assertFalse(self::domain(NamingScheme::Dotted)->needsLoginRealmHint());
    }

    public function testTheAccountLimitIsEnforcedByTheDomain(): void
    {
        $domain = self::domain(null, AccountLimit::of(2));

        self::assertTrue($domain->permitsAnotherAccount(1));
        self::assertFalse($domain->permitsAnotherAccount(2));
        self::assertFalse($domain->maxAccounts->isExceededBy(2));
        self::assertTrue($domain->maxAccounts->isExceededBy(3));
    }

    public function testTheLegacyZeroMeansUnlimited(): void
    {
        $limit = AccountLimit::fromLegacyValue(0);

        self::assertTrue($limit->isUnlimited());
        self::assertTrue($limit->permitsAnotherAccount(10_000));
        self::assertSame(0, $limit->toLegacyValue());
        self::assertNull($limit->maximum());
    }

    public function testALimitOfZeroIsNotUnlimited(): void
    {
        $limit = AccountLimit::of(0);

        self::assertFalse($limit->isUnlimited());
        self::assertFalse($limit->permitsAnotherAccount(0));
    }

    public function testANegativeLimitIsRefused(): void
    {
        $this->expectException(InvalidDomainConfiguration::class);

        AccountLimit::of(-1);
    }

    public function testTheCatchAllAliasIsSpelledLikeTheLegacyColumn(): void
    {
        self::assertSame('@example.test', self::domain(null)->catchAllAlias());
    }

    public function testDefaultFoldersKeepTheirFormatAndOrder(): void
    {
        $folders = DefaultFolders::fromLegacyString(' Sent , Trash ,, Archive/2026 ');

        self::assertSame(['Sent', 'Trash', 'Archive/2026'], $folders->names());
        self::assertSame('Sent,Trash,Archive/2026', $folders->toLegacyString());
        self::assertTrue($folders->contains('sent'));
        self::assertSame(3, $folders->count());
    }

    public function testDuplicateFoldersAreDroppedOnce(): void
    {
        self::assertSame(['Sent'], DefaultFolders::fromLegacyString('Sent,sent')->names());
    }

    public function testAnEmptyColumnMeansNoFolders(): void
    {
        self::assertTrue(DefaultFolders::fromLegacyString('')->isEmpty());
        self::assertTrue(DefaultFolders::none()->isEmpty());
    }

    public function testAFolderNameThatCyrusCouldNotCarryIsRefused(): void
    {
        $this->expectException(InvalidDomainConfiguration::class);

        DefaultFolders::of('/Sent');
    }

    public function testAFolderNameWithAnEmptyLevelIsRefused(): void
    {
        $this->expectException(InvalidDomainConfiguration::class);

        DefaultFolders::of('Archive//2026');
    }

    public function testChangesProduceANewDomain(): void
    {
        $domain = self::domain(null);
        $renamed = $domain->renamedTo(DomainName::fromString('example.invalid'));

        self::assertSame('example.test', $domain->name->toString());
        self::assertSame('example.invalid', $renamed->name->toString());
        self::assertFalse($domain->equals($renamed));

        $withScheme = $domain->withNamingScheme(NamingScheme::Fqun);
        self::assertNull($domain->namingScheme);
        self::assertSame(NamingScheme::Fqun, $withScheme->namingScheme);

        $withFolders = $domain->withDefaultFolders(DefaultFolders::of('Sent'));
        self::assertSame(['Sent'], $withFolders->defaultFolders->names());

        $withAllowances = $domain->withAllowances(
            AccountLimit::of(5),
            Quota::ofMebibytes(50),
            Quota::ofGibibytes(5),
        );
        self::assertSame(5, $withAllowances->maxAccounts->maximum());
        self::assertSame(51200, $withAllowances->defaultAccountQuota->limitKibibytes());
        self::assertSame(5242880, $withAllowances->domainQuota->limitKibibytes());
    }

    private static function domain(
        ?NamingScheme $scheme,
        ?AccountLimit $limit = null,
    ): Domain {
        return new Domain(
            DomainName::fromString('example.test'),
            $limit ?? AccountLimit::unlimited(),
            Quota::ofMebibytes(20),
            Quota::unlimited(),
            DefaultFolders::of('Sent', 'Trash'),
            $scheme,
        );
    }
}

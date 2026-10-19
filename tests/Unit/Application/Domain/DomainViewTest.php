<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Application\Domain;

use Mailadmin\Application\Domain\DomainDefaults;
use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Application\Domain\DomainView;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Tests\Support\DomainFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The representation and the validator over it (U-19).
 */
#[CoversClass(DomainView::class)]
#[CoversClass(DomainDefaults::class)]
final class DomainViewTest extends TestCase
{
    public function testUnlimitedIsNullAndNotZero(): void
    {
        // `0` in the legacy column means unlimited, and the API must not repeat
        // that ambiguity: a limit of zero is a domain nobody may add to.
        $view = DomainView::full(
            new DomainSummary(DomainFixture::domain(maxAccounts: null, accountQuotaKib: null), 0),
            new DomainDefaults(),
        );

        self::assertNull($view['max_accounts']);
        self::assertNull($view['default_account_quota_kib']);
        self::assertNull($view['domain_quota_kib']);
    }

    public function testALimitOfZeroSurvivesAsZero(): void
    {
        $view = DomainView::full(
            new DomainSummary(DomainFixture::domain(maxAccounts: 0), 0),
            new DomainDefaults(),
        );

        self::assertSame(0, $view['max_accounts']);
    }

    public function testADomainThatSaysNothingReportsInheritedAndWhatItResolvesTo(): void
    {
        $view = DomainView::full(DomainFixture::summary(), new DomainDefaults(NamingScheme::Fqun));

        self::assertSame('inherited', $view['naming_scheme']);
        self::assertSame('fqun', $view['effective_naming_scheme']);
    }

    public function testADomainSettingBeatsTheGlobalDefault(): void
    {
        $view = DomainView::full(
            new DomainSummary(DomainFixture::domain(scheme: NamingScheme::Dotted), 0),
            new DomainDefaults(NamingScheme::Fqun),
        );

        self::assertSame('dotted', $view['naming_scheme']);
        self::assertSame('dotted', $view['effective_naming_scheme']);
    }

    public function testWithNoSettingAnywhereTheFallbackIsDotted(): void
    {
        $view = DomainView::full(DomainFixture::summary(), new DomainDefaults());

        self::assertSame('dotted', $view['effective_naming_scheme']);
    }

    public function testTheCollectionShapeCarriesNothingThatCostsARoundTrip(): void
    {
        // Quota usage lives in Cyrus. Putting it in a list is what made the
        // legacy interface slow (architecture 7.2).
        $item = DomainView::listItem(DomainFixture::summary(accountCount: 3), new DomainDefaults());

        self::assertSame(3, $item['account_count']);
        self::assertArrayNotHasKey('default_folders', $item);
        self::assertArrayNotHasKey('legacy_prefix', $item);
    }

    public function testTheEtagIsWeakAndChangesWithTheRepresentation(): void
    {
        $defaults = new DomainDefaults();
        $one = DomainView::etag(DomainView::full(DomainFixture::summary(), $defaults));
        $other = DomainView::etag(DomainView::full(
            new DomainSummary(DomainFixture::domain(maxAccounts: 21), 0),
            $defaults,
        ));

        self::assertStringStartsWith('W/"', $one);
        self::assertNotSame($one, $other);
    }

    public function testTheEtagChangesWithTheAccountCount(): void
    {
        // It has to: `DELETE` is refused on a non-empty domain, and the client's
        // If-Match is what makes "it was empty when I looked" checkable (U-49).
        $defaults = new DomainDefaults();

        self::assertNotSame(
            DomainView::etag(DomainView::full(DomainFixture::summary(accountCount: 0), $defaults)),
            DomainView::etag(DomainView::full(DomainFixture::summary(accountCount: 1), $defaults)),
        );
    }

    public function testAnEtagMatchesItselfInEveryFormAClientMightEchoBack(): void
    {
        $etag = DomainView::etag(DomainView::full(DomainFixture::summary(), new DomainDefaults()));
        $bare = trim(substr($etag, 2), '"');

        self::assertTrue(DomainView::matchesEtag($etag, $etag));
        self::assertTrue(DomainView::matchesEtag('"' . $bare . '"', $etag));
        self::assertTrue(DomainView::matchesEtag('"other", ' . $etag, $etag));
        self::assertTrue(DomainView::matchesEtag('*', $etag));
        self::assertFalse(DomainView::matchesEtag('W/"nope"', $etag));
        self::assertFalse(DomainView::matchesEtag('', $etag));
    }

    public function testTheExternalStepsAreStableKeysAndMentionTheLoginRealmOnlyForFqun(): void
    {
        // E-16: the API is language neutral. A sentence here would make the
        // hint untranslatable and untestable.
        $dotted = DomainView::nextSteps(DomainFixture::summary(), new DomainDefaults(NamingScheme::Dotted));
        $fqun = DomainView::nextSteps(DomainFixture::summary(), new DomainDefaults(NamingScheme::Fqun));

        self::assertSame(['salt_pillar', 'salt_apply', 'dns_mx', 'dns_spf', 'dns_dkim', 'dns_dmarc'], $dotted);
        self::assertContains('cyrus_loginrealm', $fqun);
    }
}

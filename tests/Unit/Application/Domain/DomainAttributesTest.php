<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Application\Domain;

use Mailadmin\Application\Domain\DomainAttributes;
use Mailadmin\Application\Domain\DomainChange;
use Mailadmin\Application\Domain\DomainDraft;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Exception\InvalidDomainConfiguration;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Tests\Support\DomainFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Absent, `null` and a value are three different things in a `PATCH`.
 *
 * That distinction is the whole reason {@see DomainAttributes} keeps the raw map
 * instead of a set of nullable properties, and it is the thing a later
 * refactoring is most likely to lose.
 */
#[CoversClass(DomainAttributes::class)]
#[CoversClass(DomainChange::class)]
#[CoversClass(DomainDraft::class)]
final class DomainAttributesTest extends TestCase
{
    public function testAFieldTheBodyDidNotMentionIsLeftAlone(): void
    {
        $current = DomainFixture::domain(maxAccounts: 20, accountQuotaKib: 20000);
        $updated = DomainAttributes::fromRequest(['free_names' => true])->applyTo($current);

        self::assertSame(20, $updated->maxAccounts->maximum());
        self::assertSame(20000, $updated->defaultAccountQuota->limitKibibytes());
        self::assertTrue($updated->freeNames);
    }

    public function testAnExplicitNullMeansUnlimitedAndIsNotTheSameAsSilence(): void
    {
        $current = DomainFixture::domain(maxAccounts: 20);

        self::assertNull(
            DomainAttributes::fromRequest(['max_accounts' => null])->applyTo($current)->maxAccounts->maximum(),
        );
        self::assertSame(
            20,
            DomainAttributes::fromRequest([])->applyTo($current)->maxAccounts->maximum(),
        );
    }

    public function testAZeroLimitIsStoredAsAZeroLimit(): void
    {
        $updated = DomainAttributes::fromRequest(['max_accounts' => 0])
            ->applyTo(DomainFixture::domain());

        self::assertFalse($updated->maxAccounts->isUnlimited());
        self::assertSame(0, $updated->maxAccounts->maximum());
    }

    public function testInheritedIsAValueAndClearsTheDomainSetting(): void
    {
        $current = DomainFixture::domain(scheme: NamingScheme::Fqun);

        self::assertNull(
            DomainAttributes::fromRequest(['naming_scheme' => 'inherited'])->applyTo($current)->namingScheme,
        );
        self::assertSame(
            NamingScheme::Fqun,
            DomainAttributes::fromRequest([])->applyTo($current)->namingScheme,
        );
    }

    public function testThePrefixAndTheTransportAreNeverWritable(): void
    {
        // U-46 and U-47: both are carried, neither is offered. A body that
        // somehow got past `additionalProperties: false` changes nothing.
        $current = DomainFixture::domain(prefix: 'kept');
        $updated = DomainAttributes::fromRequest([
            'legacy_prefix' => 'stolen',
            'transport' => 'smtp:[elsewhere]',
        ])->applyTo($current);

        self::assertSame('kept', $updated->legacyPrefix);
        self::assertSame('cyrus', $updated->transport);
    }

    public function testAnUnusableFolderNameIsRejectedRatherThanDropped(): void
    {
        // On the way in. A folder already in `domain.folders` is a different
        // question and is answered by the repository (U-22).
        $this->expectException(InvalidDomainConfiguration::class);

        DomainAttributes::fromRequest(['default_folders' => ['Sent', 'bad@name']])
            ->applyTo(DomainFixture::domain());
    }

    public function testANewDomainGetsWebcyradmsColumnDefaults(): void
    {
        $domain = DomainDraft::fromRequest(['name' => 'neu.test'])->toDomain('neu.test');

        self::assertSame(20, $domain->maxAccounts->maximum());
        self::assertSame(20000, $domain->defaultAccountQuota->limitKibibytes());
        self::assertTrue($domain->domainQuota->isUnlimited());
        self::assertSame('cyrus', $domain->transport);
        self::assertFalse($domain->freeNames);
        self::assertFalse($domain->freeAddress);
        self::assertTrue($domain->defaultFolders->isEmpty());
        self::assertNull($domain->namingScheme);
    }

    public function testANewNameGoesThroughTheStrictDoor(): void
    {
        // The tolerant door exists for rows that are already there (U-22).
        // Nothing forces anyone to create a second unconventional name.
        $this->expectException(InvalidAddress::class);

        DomainDraft::fromRequest(['name' => 'not_a_domain']);
    }

    public function testAChangeWithoutANameIsNotARename(): void
    {
        $change = DomainChange::fromRequest(['max_accounts' => 5]);

        self::assertNull($change->renameTarget(DomainFixture::name()));
        self::assertFalse($change->isEmpty());
    }

    public function testSendingTheNameItAlreadyHasIsNotARename(): void
    {
        $change = DomainChange::fromRequest(['name' => 'example.test']);

        self::assertNull($change->renameTarget(DomainFixture::name('example.test')));
    }

    public function testADifferentSpellingOfTheSameNameIsARename(): void
    {
        // Cyrus and `accountuser.username` hold the exact bytes, so this really
        // does have to move mailboxes.
        $change = DomainChange::fromRequest(['name' => 'example.test']);
        $target = $change->renameTarget(DomainFixture::name('Example.test'));

        self::assertNotNull($target);
        self::assertSame('example.test', $target->toString());
    }

    public function testOnlyTheBooleanTrueConfirmsARename(): void
    {
        self::assertTrue(DomainChange::fromRequest(['confirm_rename' => true])->confirmsRename());
        self::assertFalse(DomainChange::fromRequest(['confirm_rename' => 'yes'])->confirmsRename());
        self::assertFalse(DomainChange::fromRequest(['confirm_rename' => 1])->confirmsRename());
        self::assertFalse(DomainChange::fromRequest([])->confirmsRename());
    }
}

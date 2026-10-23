<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Maintenance;

use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Application\MailingList\ListDeliveryMode;
use Mailadmin\Application\Maintenance\FindingCategory;
use Mailadmin\Application\Maintenance\Reconcile;
use Mailadmin\Application\Maintenance\ReconciliationReport;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Repair\InMemoryMaintenanceInventory;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * The reconciliation reports, and only reports (U-115, U-119). Every category
 * has a case here; a clean state has none.
 */
final class ReconcileTest extends TestCase
{
    private InMemoryMaintenanceInventory $inventory;
    private InMemoryMailstore $mailstore;

    protected function setUp(): void
    {
        $this->inventory = (new InMemoryMaintenanceInventory())
            ->givenDomain('example.test')
            ->givenDomain('relay.test', 'smtp:[relay.invalid]');
        $this->mailstore = new InMemoryMailstore();
    }

    public function testACleanStateHasNoFindings(): void
    {
        $this->givenAccount('anna.example.test');
        $this->givenAccount('bert@example.test');
        $this->inventory
            ->givenRow(new VirtualRow('anna@example.test', 'anna.example.test', 'anna.example.test'))
            ->givenRow(new VirtualRow('bert@example.test', 'bert@example.test', 'bert@example.test'))
            ->givenRow(new VirtualRow('info@example.test', 'anna.example.test', 'example.test'))
            ->givenRow(new VirtualRow('team@example.test', 'info@example.test,someone@elsewhere.invalid', 'example.test'));

        $report = $this->reconcile();

        self::assertSame([], $report->findings);
        self::assertSame(2, $report->accounts);
        self::assertSame(2, $report->mailboxes);
        self::assertSame(4, $report->virtualRows);
        self::assertSame([], $this->mailstore->operations(), 'The reconciliation changes nothing.');
    }

    public function testAnAccountWithoutMailboxIsReported(): void
    {
        $this->inventory->givenAccount('anna.example.test', 'example.test');

        $findings = $this->reconcile()->of(FindingCategory::AccountWithoutMailbox);

        self::assertCount(1, $findings);
        self::assertSame('anna.example.test', $findings[0]->subject);
        self::assertSame('example.test', $findings[0]->domain);
        self::assertSame('user/anna.example.test', $findings[0]->related);
    }

    public function testAMailboxWithoutAccountIsReported(): void
    {
        $this->mailstore->givenOrphan('user/ghost.example.test');

        $findings = $this->reconcile()->of(FindingCategory::MailboxWithoutAccount);

        self::assertCount(1, $findings);
        self::assertSame('user/ghost.example.test', $findings[0]->subject);
        self::assertNull($findings[0]->domain, 'An orphan is not decomposed to guess its domain.');
    }

    public function testDeletedMailboxesAreNotOrphans(): void
    {
        $this->mailstore->givenOrphan('DELETED/user/ghost.example.test/5F000000');

        self::assertSame([], $this->reconcile()->findings);
    }

    public function testTheMailboxOfTheOtherSchemeIsAShadowNotAnOrphan(): void
    {
        $this->givenAccount('anna.example.test');
        $this->mailstore->givenOrphan('user/anna@example.test');

        $report = $this->reconcile();

        self::assertSame([], $report->of(FindingCategory::MailboxWithoutAccount));
        $shadows = $report->of(FindingCategory::ShadowMailbox);
        self::assertCount(1, $shadows);
        self::assertSame('user/anna@example.test', $shadows[0]->subject);
        self::assertSame('anna.example.test', $shadows[0]->related);
        self::assertSame('fqun', $shadows[0]->reason);
    }

    public function testARowWhoseNameDoesNotFitItsDomainIsReportedOnce(): void
    {
        $this->inventory->givenAccount('bob.other.test', 'example.test');
        $this->mailstore->givenOrphan('user/bob.other.test');

        $report = $this->reconcile();

        self::assertCount(1, $report->findings);
        self::assertSame(FindingCategory::UnresolvableAccount, $report->findings[0]->category);
        self::assertSame('name_does_not_match_domain', $report->findings[0]->reason);
    }

    public function testAUsernameThatASubdomainAlsoReachesIsAmbiguous(): void
    {
        $this->inventory->givenDomain('kunde.example.test');
        $this->givenAccount('info.kunde.example.test');

        $findings = $this->reconcile()->of(FindingCategory::AmbiguousUsername);

        self::assertCount(1, $findings);
        self::assertSame('info.kunde.example.test', $findings[0]->subject);
        self::assertSame('kunde.example.test', $findings[0]->related);
    }

    public function testTheAdministratorsOwnMailboxIsNoFinding(): void
    {
        $this->inventory->givenAccount('cyrus', '');
        $this->mailstore->givenOrphan('user/cyrus');

        self::assertSame([], $this->reconcile()->findings);
    }

    public function testAnUnclassifiableRowIsShownNotSkipped(): void
    {
        $this->inventory->givenRow(new VirtualRow('', 'anna.example.test', 'anna.example.test'));

        $findings = $this->reconcile()->of(FindingCategory::VirtualUnclassified);

        self::assertCount(1, $findings);
        self::assertSame('no_alias', $findings[0]->reason);
    }

    public function testARowOwnedByAMissingAccountIsReported(): void
    {
        $this->inventory->givenRow(new VirtualRow('old@example.test', 'someone@elsewhere.invalid', 'old.example.test'));

        $findings = $this->reconcile()->of(FindingCategory::VirtualOwnerMissing);

        self::assertCount(1, $findings);
        self::assertSame('old@example.test', $findings[0]->subject);
        self::assertSame('example.test', $findings[0]->domain);
        self::assertSame('old.example.test', $findings[0]->related);
    }

    public function testADottedTargetWithoutAccountIsReportedAsWrittenNeverDecomposed(): void
    {
        $this->inventory->givenRow(new VirtualRow('info@example.test', 'info.kunde.example.test', 'example.test'));

        $findings = $this->reconcile()->of(FindingCategory::VirtualTargetMissing);

        self::assertCount(1, $findings);
        self::assertSame('info.kunde.example.test', $findings[0]->related);
        self::assertSame('no_account', $findings[0]->reason);
        self::assertSame('example.test', $findings[0]->domain, 'The domain is the alias\'s, not one read out of the target.');
    }

    public function testATargetInALocalDomainThatNothingAnswersIsReported(): void
    {
        $this->inventory->givenRow(new VirtualRow('info@example.test', 'nobody@example.test', 'example.test'));

        $findings = $this->reconcile()->of(FindingCategory::VirtualTargetMissing);

        self::assertCount(1, $findings);
        self::assertSame('nobody@example.test', $findings[0]->related);
        self::assertSame('no_address', $findings[0]->reason);
    }

    public function testTargetsAnsweredByAListACatchallOrAnotherDomainAreNotReported(): void
    {
        $this->inventory
            ->givenDomain('lists.test')
            ->givenDomain('catch.test')
            ->givenList('team@lists.test')
            ->givenRow(new VirtualRow('@catch.test', 'someone@elsewhere.invalid', 'catch.test'))
            ->givenRow(new VirtualRow('a@example.test', 'team@lists.test', 'example.test'))
            ->givenRow(new VirtualRow('b@example.test', 'anything@catch.test', 'example.test'))
            ->givenRow(new VirtualRow('c@example.test', 'nobody@relay.test', 'example.test'))
            ->givenRow(new VirtualRow('d@example.test', 'nobody@elsewhere.invalid', 'example.test'));

        self::assertSame([], $this->reconcile()->of(FindingCategory::VirtualTargetMissing));
    }

    public function testMailingListEntriesInEitherFormAreNotReported(): void
    {
        // The legacy form names a "user" that is the list key and no account;
        // the transport form names the pseudo-domain. Both are lists, and
        // neither is a missing owner or target.
        $this->inventory
            ->givenList('team@example.test')
            ->givenList('crew@example.test')
            ->givenRow(new VirtualRow('team@example.test', 'team.example.test', 'team.example.test'))
            ->givenRow(new VirtualRow('crew@example.test', 'crew.example.test@maillist.invalid', ''));

        self::assertSame([], $this->reconcile()->findings);
    }

    public function testALegacyShapedRowWithoutAListIsStillReported(): void
    {
        $this->inventory->givenRow(new VirtualRow('crew@example.test', 'crew.example.test', 'crew.example.test'));

        self::assertCount(1, $this->reconcile()->of(FindingCategory::VirtualOwnerMissing));
    }

    public function testADestinationNamingAnUnresolvableAccountIsNotASecondFinding(): void
    {
        // One broken accountuser row — an fqun name outside its domain — and
        // the address row that delivers to it.
        $this->inventory
            ->givenDomain('other.test')
            ->givenAccount('bob@other.test', 'example.test')
            ->givenRow(new VirtualRow('bob@example.test', 'bob@other.test', 'bob@other.test'));
        $this->mailstore->givenOrphan('user/bob@other.test');

        $report = $this->reconcile();

        self::assertCount(1, $report->findings, 'One problem, one finding.');
        self::assertSame(FindingCategory::UnresolvableAccount, $report->findings[0]->category);
    }

    public function testATargetWithARecipientDelimiterExtensionIsAnsweredByItsBaseAddress(): void
    {
        $this->givenAccount('anna.example.test');
        $this->inventory
            ->givenRow(new VirtualRow('anna@example.test', 'anna.example.test', 'anna.example.test'))
            ->givenRow(new VirtualRow('news@example.test', 'anna+news@example.test', 'example.test'));

        self::assertSame([], $this->reconcile()->of(FindingCategory::VirtualTargetMissing));
    }

    public function testAnExtensionIsNotStrippedBeforeTheBaseAddressItselfIsMissing(): void
    {
        $this->inventory->givenRow(new VirtualRow('news@example.test', 'nobody+news@example.test', 'example.test'));

        $findings = $this->reconcile()->of(FindingCategory::VirtualTargetMissing);

        self::assertCount(1, $findings);
        self::assertSame('nobody+news@example.test', $findings[0]->related, 'Reported as written.');
    }

    public function testATargetAnsweredOnlyByADisabledRowIsReported(): void
    {
        $this->givenAccount('anna.example.test');
        $this->inventory
            ->givenRow(new VirtualRow('info@example.test', 'anna.example.test', 'example.test', status: 0))
            ->givenRow(new VirtualRow('team@example.test', 'info@example.test', 'example.test'))
            ->givenRow(new VirtualRow('@catch.test', 'someone@elsewhere.invalid', 'catch.test', status: 0))
            ->givenDomain('catch.test')
            ->givenRow(new VirtualRow('sales@example.test', 'anyone@catch.test', 'example.test'));

        $findings = $this->reconcile()->of(FindingCategory::VirtualTargetMissing);

        self::assertSame(
            ['info@example.test', 'anyone@catch.test'],
            array_map(static fn ($finding): ?string => $finding->related, $findings),
            'Postfix delivers only status = 1; a disabled alias or catchall answers nothing.',
        );
    }

    public function testTheReportCarriesASummaryOfEveryCategory(): void
    {
        $this->inventory->givenAccount('anna.example.test', 'example.test');
        $this->mailstore->givenOrphan('user/ghost.example.test');

        $representation = $this->reconcile()->toRepresentation();

        self::assertSame('2026-01-01T12:00:00Z', $representation['checked_at']);
        self::assertSame(['accounts' => 1, 'mailboxes' => 1, 'virtual_rows' => 0], $representation['checked']);
        self::assertSame(count(FindingCategory::cases()), count($representation['summary']));
        self::assertSame(1, $representation['summary']['account_without_mailbox']);
        self::assertSame(1, $representation['summary']['mailbox_without_account']);
        self::assertSame(0, $representation['summary']['shadow_mailbox']);
        self::assertCount(2, $representation['findings']);
        self::assertSame(
            ['category', 'subject', 'domain', 'related', 'reason'],
            array_keys($representation['findings'][0]),
        );
    }

    private function givenAccount(string $username, string $domain = 'example.test'): void
    {
        $this->inventory->givenAccount($username, $domain);
        $this->mailstore->given(MailboxIdentity::fromStoredUsername($username, DomainName::fromStoredValue($domain)));
        $this->mailstore->forget();
    }

    private function reconcile(): ReconciliationReport
    {
        return (new Reconcile(
            $this->inventory,
            $this->mailstore,
            new FrozenClock(),
            new ListDelivery(DomainName::fromString('maillist.invalid'), ListDeliveryMode::Transport),
        ))
            ->handle(DomainFixture::superuser());
    }
}

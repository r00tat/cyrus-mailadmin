<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Address;

use Mailadmin\Application\Address\AddressErrorCode;
use Mailadmin\Application\Address\AddressView;
use Mailadmin\Application\Address\DeleteForwards;
use Mailadmin\Application\Address\DeleteVacation;
use Mailadmin\Application\Address\ForwardChange;
use Mailadmin\Application\Address\SetForwards;
use Mailadmin\Application\Address\SetVacation;
use Mailadmin\Application\Address\VacationDraft;
use Mailadmin\Application\Address\VacationKind;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Forwards (U-107) and the vacation notice (U-102) — the two things that hang
 * off one account rather than off an address.
 */
#[CoversClass(SetForwards::class)]
#[CoversClass(DeleteForwards::class)]
#[CoversClass(\Mailadmin\Application\Address\GetForwards::class)]
#[CoversClass(SetVacation::class)]
#[CoversClass(DeleteVacation::class)]
#[CoversClass(\Mailadmin\Application\Address\GetVacation::class)]
#[CoversClass(\Mailadmin\Application\Address\VacationScripts::class)]
#[CoversClass(\Mailadmin\Application\Address\VacationState::class)]
final class AccountAddressesUseCaseTest extends AddressUseCaseTestCase
{
    private const string ANNA = 'anna.example.test';
    private const string CARL = 'carl@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->givenAccount(self::ANNA);
        $this->givenAccount(self::CARL);
        $this->givenAccount('mia.other.test', 'other.test');
    }

    // -----------------------------------------------------------------
    // Forwards
    // -----------------------------------------------------------------

    public function testAForwardWithACopyAppendsTheAccountItself(): void
    {
        $state = $this->setForwards()->handle(
            DomainFixture::superuser(),
            self::ANNA,
            new ForwardChange(['Extern@Elsewhere.test']),
            null,
        );

        self::assertSame(['anna.example.test|Extern@Elsewhere.test,anna.example.test||1'], $this->forwardRows(self::ANNA));
        self::assertSame(['Extern@Elsewhere.test'], $state->destinations()->all());
        self::assertTrue($state->keepsCopy());
        self::assertCount(1, $this->audit->withAction(SetForwards::OPERATION));
    }

    public function testAForwardWithoutACopyLeavesTheMailboxOut(): void
    {
        $state = $this->setForwards()->handle(
            DomainFixture::superuser(),
            self::ANNA,
            new ForwardChange(['extern@elsewhere.test'], keepCopy: false),
            null,
        );

        self::assertSame(['anna.example.test|extern@elsewhere.test||1'], $this->forwardRows(self::ANNA));
        self::assertFalse($state->keepsCopy());
    }

    public function testAnFqunAccountAlwaysKeepsACopy(): void
    {
        $failure = $this->expectFailure(fn () => $this->setForwards()->handle(
            DomainFixture::superuser(),
            self::CARL,
            new ForwardChange(['extern@elsewhere.test'], keepCopy: false),
            null,
        ));

        self::assertSame(AddressErrorCode::FORWARD_COPY_REQUIRED, $failure->errorCode);
        self::assertSame([], $this->forwardRows(self::CARL));
    }

    public function testAForwardOntoTheAccountItselfIsALoop(): void
    {
        $this->expectException(InvalidAddress::class);
        $this->setForwards()->handle(DomainFixture::superuser(), self::ANNA, new ForwardChange(['ANNA.example.test']), null);
    }

    public function testForwardsBetweenTwoAccountsWithoutACopyAreALoop(): void
    {
        // Review finding on A3: anna -> carl and carl -> anna, nobody keeps a copy.
        $this->givenAccount('ben.example.test');
        $this->setForwards()->handle(DomainFixture::superuser(), self::ANNA, new ForwardChange(['ben.example.test'], keepCopy: false), null);

        try {
            $this->setForwards()->handle(DomainFixture::superuser(), 'ben.example.test', new ForwardChange(['anna@example.test'], keepCopy: false), null);
            self::fail('anna -> ben -> anna was accepted');
        } catch (InvalidAddress) {
        }

        self::assertSame([], $this->forwardRows('ben.example.test'));
    }

    public function testForwardsBetweenTwoAccountsThatKeepACopyAreNoLoop(): void
    {
        // Each address expands into itself once, and that ends the chain.
        $this->givenAccount('ben.example.test');
        $this->setForwards()->handle(DomainFixture::superuser(), self::ANNA, new ForwardChange(['ben.example.test']), null);
        $this->setForwards()->handle(DomainFixture::superuser(), 'ben.example.test', new ForwardChange(['anna.example.test']), null);

        self::assertSame(['ben.example.test|anna.example.test,ben.example.test||1'], $this->forwardRows('ben.example.test'));
    }

    public function testReplacingAForwardRewritesItsRows(): void
    {
        $this->virtual->givenRow(self::ANNA, 'one@elsewhere.test', '');
        $this->virtual->givenRow(self::ANNA, 'two@elsewhere.test', '');

        $before = $this->getForwards()->handle(DomainFixture::superuser(), self::ANNA);
        self::assertSame(['one@elsewhere.test', 'two@elsewhere.test'], $before->destinations()->all());
        self::assertFalse($before->keepsCopy());

        $this->setForwards()->handle(
            DomainFixture::superuser(),
            self::ANNA,
            new ForwardChange(['three@elsewhere.test']),
            AddressView::etag(AddressView::forwards($before)),
        );

        self::assertSame(['anna.example.test|three@elsewhere.test,anna.example.test||1'], $this->forwardRows(self::ANNA));
    }

    public function testDeletingForwardsLeavesTheAddressRowAndIsIdempotent(): void
    {
        $this->virtual->givenRow(self::ANNA, 'one@elsewhere.test', '');

        $this->deleteForwards()->handle(DomainFixture::superuser(), self::ANNA, null);
        $this->deleteForwards()->handle(DomainFixture::superuser(), self::ANNA, null);

        self::assertSame([], $this->forwardRows(self::ANNA));
        self::assertCount(1, $this->rowsOf('anna@example.test'));
        self::assertCount(1, $this->audit->withAction(DeleteForwards::OPERATION));
    }

    public function testAForwardChangeWithoutItsAuditEntryIsSetBack(): void
    {
        $this->virtual->givenRow(self::ANNA, 'one@elsewhere.test', '');
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->setForwards()->handle(DomainFixture::superuser(), self::ANNA, new ForwardChange(['two@elsewhere.test']), null);
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertSame(['anna.example.test|one@elsewhere.test||1'], $this->forwardRows(self::ANNA));
    }

    public function testAnotherDomainsAccountAndAMissingOneAreRefusedAlike(): void
    {
        $actor = DomainFixture::domainMaster('anna', 'example.test');

        try {
            $this->getForwards()->handle($actor, 'mia.other.test');
            self::fail('expected a refusal');
        } catch (ResourceNotVisible) {
        }

        $failure = $this->expectFailure(fn () => $this->getForwards()->handle($actor, 'nobody.example.test'));
        self::assertSame(404, $failure->status);
    }

    // -----------------------------------------------------------------
    // Vacation
    // -----------------------------------------------------------------

    public function testWithoutAScriptThereIsNoNotice(): void
    {
        $state = $this->getVacation()->handle(DomainFixture::superuser(), self::ANNA);

        self::assertSame(VacationKind::None, $state->kind);
        self::assertNull(AddressView::vacation($state)['message']);
    }

    public function testANoticeIsRenderedInstalledAndReadBack(): void
    {
        $state = $this->setVacation()->handle(
            DomainFixture::superuser(),
            self::ANNA,
            new VacationDraft("Bin bis Montag weg.\n\"Zitat\"", 'Abwesend', 3),
            null,
        );

        self::assertSame(VacationKind::Active, $state->kind);
        self::assertSame(['anna@example.test'], $state->rule()?->addressStrings());
        self::assertSame(SieveScript::LEGACY_NAME, $state->script?->name);

        $read = $this->getVacation()->handle(DomainFixture::superuser(), self::ANNA);
        $rule = $read->rule();
        self::assertNotNull($rule);
        self::assertSame("Bin bis Montag weg.\n\"Zitat\"", $rule->message);
        self::assertSame(3, $rule->intervalDays);

        $audit = $this->audit->withAction(SetVacation::OPERATION)[0];
        self::assertArrayNotHasKey('message', $audit->after ?? []);
        self::assertSame(27, ($audit->after ?? [])['message_length']);
    }

    public function testAForeignScriptIsNeverReplacedWithoutTakeover(): void
    {
        $identity = $this->identity(self::ANNA);
        $this->sieve->givenForeignScript($identity, "require \"fileinto\";\nkeep;\n", 'roundcube');

        $before = $this->getVacation()->handle(DomainFixture::superuser(), self::ANNA);
        self::assertSame(VacationKind::Foreign, $before->kind);
        self::assertSame("require \"fileinto\";\nkeep;\n", AddressView::vacation($before)['script']);

        $failure = $this->expectFailure(fn () => $this->setVacation()->handle(
            DomainFixture::superuser(),
            self::ANNA,
            new VacationDraft('Weg.'),
            null,
        ));
        self::assertSame(AddressErrorCode::VACATION_FOREIGN_SCRIPT, $failure->errorCode);
        self::assertSame('roundcube', $this->sieve->activeScript($identity)?->name);

        $this->setVacation()->handle(DomainFixture::superuser(), self::ANNA, new VacationDraft('Weg.', takeOver: true), null);
        $active = $this->sieve->activeScript($identity);
        self::assertNotNull($active);
        self::assertSame(SieveScript::LEGACY_NAME, $active->name);
        // The foreign script is still stored; only its activation was taken.
        self::assertContains('roundcube', $this->sieve->scriptNames($identity));
    }

    public function testAnInactiveForeignScriptUnderOurNameIsReplacedOnlyWithTakeover(): void
    {
        // Review finding on A3: nothing active, but a hand-written `sieve` is
        // stored. Installing would have replaced it without asking.
        $identity = $this->identity(self::ANNA);
        $this->sieve->givenStoredScript($identity, SieveScript::of('sieve', "# by hand\r\nkeep;\r\n"));

        self::assertSame(VacationKind::None, $this->getVacation()->handle(DomainFixture::superuser(), self::ANNA)->kind);

        $failure = $this->expectFailure(fn () => $this->setVacation()->handle(
            DomainFixture::superuser(),
            self::ANNA,
            new VacationDraft('Weg.'),
            null,
        ));
        self::assertSame(AddressErrorCode::VACATION_FOREIGN_SCRIPT, $failure->errorCode);
        self::assertSame("# by hand\r\nkeep;\r\n", $this->sieve->script($identity, 'sieve')?->content);

        $state = $this->setVacation()->handle(DomainFixture::superuser(), self::ANNA, new VacationDraft('Weg.', takeOver: true), null);
        self::assertSame(VacationKind::Active, $state->kind);
    }

    public function testATakeoverWithoutItsAuditEntryPutsTheInactiveScriptBack(): void
    {
        $identity = $this->identity(self::ANNA);
        $this->sieve->givenStoredScript($identity, SieveScript::of('sieve', "# by hand\r\nkeep;\r\n"));
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->setVacation()->handle(DomainFixture::superuser(), self::ANNA, new VacationDraft('Weg.', takeOver: true), null);
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertNull($this->sieve->activeScript($identity));
        self::assertSame("# by hand\r\nkeep;\r\n", $this->sieve->script($identity, 'sieve')?->content);
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testDeletingAForeignScriptOnlyDeactivatesItAndOnlyWithTakeover(): void
    {
        $identity = $this->identity(self::ANNA);
        $this->sieve->givenForeignScript($identity, "keep;\n");

        $failure = $this->expectFailure(fn () => $this->deleteVacation()->handle(DomainFixture::superuser(), self::ANNA, false, null));
        self::assertSame(AddressErrorCode::VACATION_FOREIGN_SCRIPT, $failure->errorCode);

        $this->deleteVacation()->handle(DomainFixture::superuser(), self::ANNA, true, null);

        self::assertNull($this->sieve->activeScript($identity));
        self::assertSame([SieveScript::LEGACY_NAME], $this->sieve->scriptNames($identity));
    }

    public function testAnUnreadableScriptOfOursIsReplacedWithoutTakeover(): void
    {
        $identity = $this->identity(self::ANNA);
        $this->sieve->putScript($identity, SieveScript::of('sieve', SieveScript::markerLine() . "\r\nkeep;\r\n"));
        $this->sieve->activate($identity, 'sieve');

        self::assertSame(VacationKind::Unreadable, $this->getVacation()->handle(DomainFixture::superuser(), self::ANNA)->kind);

        $state = $this->setVacation()->handle(DomainFixture::superuser(), self::ANNA, new VacationDraft('Weg.'), null);
        self::assertSame(VacationKind::Active, $state->kind);
    }

    public function testDeletingTheNoticeWithdrawsOurScript(): void
    {
        $identity = $this->identity(self::ANNA);
        $this->setVacation()->handle(DomainFixture::superuser(), self::ANNA, new VacationDraft('Weg.'), null);

        $this->deleteVacation()->handle(DomainFixture::superuser(), self::ANNA, false, null);
        $this->deleteVacation()->handle(DomainFixture::superuser(), self::ANNA, false, null);

        self::assertNull($this->sieve->activeScript($identity));
        self::assertSame([], $this->sieve->scriptNames($identity));
        self::assertCount(1, $this->audit->withAction(DeleteVacation::OPERATION));
    }

    public function testANoticeWithoutItsAuditEntryIsWithdrawnAgain(): void
    {
        $identity = $this->identity(self::ANNA);
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->setVacation()->handle(DomainFixture::superuser(), self::ANNA, new VacationDraft('Weg.'), null);
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertNull($this->sieve->activeScript($identity));
        self::assertSame([], $this->sieve->scriptNames($identity));
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testAChangedNoticeWithoutItsAuditEntryPutsThePreviousOneBack(): void
    {
        $identity = $this->identity(self::ANNA);
        $this->setVacation()->handle(DomainFixture::superuser(), self::ANNA, new VacationDraft('Erste.'), null);
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->setVacation()->handle(DomainFixture::superuser(), self::ANNA, new VacationDraft('Zweite.'), null);
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertSame('Erste.', $this->sieve->activeScript($identity)?->ruleSet()?->vacation()?->message);
    }

    public function testAStaleEtagIsAPreconditionFailure(): void
    {
        $failure = $this->expectFailure(fn () => $this->setVacation()->handle(
            DomainFixture::superuser(),
            self::ANNA,
            new VacationDraft('Weg.'),
            '"stale"',
        ));

        self::assertSame(412, $failure->status);
    }

    public function testMalformedNoticeAddressesAreRefused(): void
    {
        $this->expectException(\Mailadmin\Domain\Exception\DomainError::class);
        $this->setVacation()->handle(
            DomainFixture::superuser(),
            self::ANNA,
            new VacationDraft('Weg.', addresses: ['no-at-sign']),
            null,
        );
    }

    public function testAForeignActorSeesNoNotice(): void
    {
        $this->expectException(ResourceNotVisible::class);
        $this->getVacation()->handle(DomainFixture::domainMaster('mia', 'other.test'), self::ANNA);
    }

    /**
     * @return list<string>
     */
    private function forwardRows(string $account): array
    {
        return array_values(array_filter(
            $this->rowsOf($account),
            static fn (string $row): bool => str_contains($row, '||'),
        ));
    }
}

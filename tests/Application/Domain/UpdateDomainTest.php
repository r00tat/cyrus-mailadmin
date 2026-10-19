<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Domain;

use Mailadmin\Application\Audit\AuditRecord;
use Mailadmin\Application\Domain\DomainChange;
use Mailadmin\Application\Domain\DomainErrorCode;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Application\Domain\DomainPrecondition;
use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Application\Domain\UpdateDomain;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Tests\Support\DomainFixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpdateDomain::class)]
#[CoversClass(DomainPrecondition::class)]
final class UpdateDomainTest extends DomainUseCaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->store->given(DomainFixture::domain('example.test'));
    }

    public function testAChangedFieldIsWrittenAndTheRestIsCarried(): void
    {
        $summary = $this->update(['max_accounts' => 5]);

        self::assertSame(5, $summary->domain->maxAccounts->maximum());
        self::assertSame(20000, $summary->domain->defaultAccountQuota->limitKibibytes());
        self::assertSame(['update example.test'], $this->store->operations());
    }

    public function testAMissingIfMatchIsFourTwentyEightAndChangesNothing(): void
    {
        // U-49 makes the header mandatory here. `428` and not `422`: the request
        // is not malformed, it is missing a precondition the client can supply
        // by reading the resource first.
        $failure = $this->refusal(['max_accounts' => 5], ifMatch: null);

        self::assertSame(DomainErrorCode::PRECONDITION_REQUIRED, $failure->errorCode);
        self::assertSame(428, $failure->status);
        self::assertSame([], $this->store->operations());
    }

    public function testAStaleIfMatchIsFourTwelve(): void
    {
        $failure = $this->refusal(['max_accounts' => 5], ifMatch: 'W/"something-else"');

        self::assertSame(DomainErrorCode::PRECONDITION_FAILED, $failure->errorCode);
        self::assertSame(412, $failure->status);
        self::assertSame([], $this->store->operations());
    }

    public function testTheEtagFromTheLastReadIsAccepted(): void
    {
        $this->update(['max_accounts' => 5]);

        // And the old one is not, because the representation moved on.
        self::assertSame(
            DomainErrorCode::PRECONDITION_FAILED,
            $this->refusal(['max_accounts' => 6], ifMatch: 'W/"' . str_repeat('0', 64) . '"')->errorCode,
        );
    }

    public function testARenameWithoutConfirmationIsRefusedBeforeAnythingMoves(): void
    {
        $this->store->given(DomainFixture::domain('example.test'), 'anna.example.test');
        $this->giveMailbox('anna.example.test', 'example.test');

        $failure = $this->refusal(['name' => 'neu.test']);

        self::assertSame(DomainErrorCode::RENAME_NOT_CONFIRMED, $failure->errorCode);
        self::assertSame(422, $failure->status);
        self::assertSame([], $this->store->operations());
        self::assertTrue($this->mailstore->mailboxExists(
            $this->giveMailboxIdentity(),
        ));
    }

    public function testAConfirmedRenameMovesTheDomainAndKeepsTheOtherChanges(): void
    {
        $summary = $this->update([
            'name' => 'neu.test',
            'confirm_rename' => true,
            'max_accounts' => 7,
        ]);

        self::assertSame('neu.test', $summary->domain->name->toString());
        self::assertSame(7, $summary->domain->maxAccounts->maximum());
        self::assertFalse($this->store->exists(DomainFixture::name('example.test')));
    }

    public function testRenamingOntoAnExistingDomainIsAConflict(): void
    {
        $this->store->given(DomainFixture::domain('belegt.test'));

        $failure = $this->refusal(['name' => 'belegt.test', 'confirm_rename' => true]);

        self::assertSame(DomainErrorCode::NAME_TAKEN, $failure->errorCode);
        self::assertSame(409, $failure->status);
    }

    public function testADomainThatIsNotThereIsAPlainNotFound(): void
    {
        $failure = null;

        try {
            $this->updateDomain()->handle(
                DomainFixture::superuser(),
                DomainFixture::name('weg.test'),
                DomainChange::fromRequest(['max_accounts' => 1]),
                '*',
            );
        } catch (DomainOperationFailed $caught) {
            $failure = $caught;
        }

        self::assertNotNull($failure);
        self::assertSame(404, $failure->status);
        // The same code a domain outside the actor's scope produces, so the two
        // answers cannot be told apart (CLAUDE.md, U-44).
        self::assertSame(DomainErrorCode::NOT_FOUND, $failure->errorCode);
    }

    public function testADomainMasterMayNotChangeADomainTheyCanSee(): void
    {
        // Reading is scoped, writing is superuser-only — web-cyradm's own split
        // (U-43). So this is `403` and not `404`: the operation is out of reach,
        // which says nothing about the object.
        $this->expectException(AccessDenied::class);

        $this->updateDomain()->handle(
            DomainFixture::domainMaster('anna', 'example.test'),
            DomainFixture::name('example.test'),
            DomainChange::fromRequest(['max_accounts' => 1]),
            '*',
        );
    }

    public function testTheAuditEntryCarriesBothStates(): void
    {
        $this->update(['max_accounts' => 5]);

        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('domain.update', $entries[0]->action);
        self::assertSame('example.test', $entries[0]->objectId);
        self::assertNotSame(5, $entries[0]->before['max_accounts'] ?? null);
        self::assertSame(5, $entries[0]->after['max_accounts'] ?? null);
    }

    public function testARenameWithAttributeChangesIsTwoOperationsInTheTrail(): void
    {
        $this->update([
            'name' => 'neu.test',
            'confirm_rename' => true,
            'max_accounts' => 7,
        ]);

        self::assertSame(
            ['domain.rename', 'domain.update'],
            array_map(static fn (AuditRecord $record): string => $record->action, $this->audit->all()),
        );
        self::assertSame('neu.test', $this->audit->last()?->objectId);
    }

    public function testARefusedUpdateWritesNoEntry(): void
    {
        $this->refusal(['max_accounts' => 5], ifMatch: null);

        self::assertTrue($this->audit->isEmpty());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function update(array $body): DomainSummary
    {
        return $this->updateDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('example.test'),
            DomainChange::fromRequest($body),
            $this->currentEtag('example.test'),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function refusal(array $body, ?string $ifMatch = 'current'): DomainOperationFailed
    {
        try {
            $this->updateDomain()->handle(
                DomainFixture::superuser(),
                DomainFixture::name('example.test'),
                DomainChange::fromRequest($body),
                $ifMatch === 'current' ? $this->currentEtag('example.test') : $ifMatch,
            );
        } catch (DomainOperationFailed $failure) {
            return $failure;
        }

        self::fail('The update was expected to be refused.');
    }

    private function giveMailboxIdentity(): MailboxIdentity
    {
        return MailboxIdentity::fromStoredUsername(
            'anna.example.test',
            DomainFixture::name('example.test'),
        );
    }
}

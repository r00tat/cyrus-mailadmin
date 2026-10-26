<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Domain;

use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Domain\CreateDomain;
use Mailadmin\Application\Domain\DomainDraft;
use Mailadmin\Application\Domain\DomainErrorCode;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Domain\Model\LegacyPrefix;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Tests\Support\DomainFixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CreateDomain::class)]
final class CreateDomainTest extends DomainUseCaseTestCase
{
    public function testANewDomainIsStoredWithADerivedLegacyPrefix(): void
    {
        $summary = $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'neu.test']),
        );

        self::assertSame('neu.test', $summary->domain->name->toString());
        self::assertSame('neu.test', $summary->domain->legacyPrefix);
        self::assertSame(0, $summary->accountCount);
        self::assertSame(['create neu.test'], $this->store->operations());
    }

    public function testACollidingPrefixFallsThroughToTheNextCandidate(): void
    {
        // U-46: the column is `NOT NULL UNIQUE` and the value is derived, so a
        // collision has to resolve itself rather than ask a human for a name
        // nothing reads.
        $this->store->given(DomainFixture::domain('other.test', prefix: 'neu.test'));

        $summary = $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'neu.test']),
        );

        self::assertNotSame('neu.test', $summary->domain->legacyPrefix);
        self::assertContains(
            $summary->domain->legacyPrefix,
            LegacyPrefix::candidatesFor(DomainFixture::name('neu.test')),
        );
    }

    public function testWhenEveryCandidateIsTakenTheAnswerIsAConflictRatherThanALongerLoop(): void
    {
        foreach (LegacyPrefix::candidatesFor(DomainFixture::name('neu.test')) as $index => $candidate) {
            $this->store->given(DomainFixture::domain('filler' . $index . '.test', prefix: $candidate));
        }

        $failure = $this->refusal(['name' => 'neu.test']);

        self::assertSame(DomainErrorCode::PREFIX_TAKEN, $failure->errorCode);
        self::assertSame(409, $failure->status);
    }

    public function testAnExistingNameIsAConflict(): void
    {
        $this->store->given(DomainFixture::domain('neu.test'));

        $failure = $this->refusal(['name' => 'neu.test']);

        self::assertSame(DomainErrorCode::NAME_TAKEN, $failure->errorCode);
        self::assertSame(409, $failure->status);
    }

    public function testANameThatBelongsToSomebodyElseIsStillTaken(): void
    {
        // Deliberately not scoped. A superuser is the only role that gets here,
        // but the principle holds: telling a caller that a name is free when it
        // is not would make the next insert fail as a `500`.
        $this->store->given(DomainFixture::domain('fremd.test'));

        self::assertSame(
            DomainErrorCode::NAME_TAKEN,
            $this->refusal(['name' => 'fremd.test'])->errorCode,
        );
    }

    public function testADomainMasterMayNotCreateADomain(): void
    {
        // A1's Definition of Done names this case. web-cyradm refused it in
        // `newdomain` and the permission split keeps that (U-43).
        $this->expectException(AccessDenied::class);

        $this->createDomain()->handle(
            DomainFixture::domainMaster('anna', 'example.test'),
            DomainDraft::fromRequest(['name' => 'neu.test']),
        );
    }

    public function testTheRequestedNamingSchemeIsCarriedIntoTheStoredDomain(): void
    {
        $summary = $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'neu.test', 'naming_scheme' => 'fqun']),
        );

        self::assertSame(NamingScheme::Fqun, $summary->domain->namingScheme);
    }

    public function testNothingReachesTheMailStore(): void
    {
        // A domain has no mailbox; accounts do (A2). So there is no ordering
        // question here, and no reason to need a mail server to add a domain.
        $this->mailstore->makeUnreachable();

        $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'neu.test']),
        );

        self::assertSame([], $this->mailstore->operations());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function refusal(array $body): DomainOperationFailed
    {
        try {
            $this->createDomain()->handle(DomainFixture::superuser(), DomainDraft::fromRequest($body));
        } catch (DomainOperationFailed $failure) {
            return $failure;
        }

        self::fail('The creation was expected to be refused.');
    }

    public function testTheAuditEntryRecordsTheCreationWithTheRepresentation(): void
    {
        $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'neu.test', 'max_accounts' => 3]),
        );

        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('domain.create', $entries[0]->action);
        self::assertSame('domain', $entries[0]->objectType);
        self::assertSame('neu.test', $entries[0]->objectId);
        self::assertSame('neu.test', $entries[0]->domain);
        self::assertSame(AuditOutcome::Succeeded, $entries[0]->outcome);
        self::assertNull($entries[0]->before, 'A creation has no previous state (U-61).');
        self::assertSame('neu.test', $entries[0]->after['name'] ?? null);
        self::assertSame(3, $entries[0]->after['max_accounts'] ?? null);
    }

    public function testAConflictWritesNoEntry(): void
    {
        $this->store->given(DomainFixture::domain('neu.test'));

        try {
            $this->createDomain()->handle(DomainFixture::superuser(), DomainDraft::fromRequest(['name' => 'neu.test']));
        } catch (DomainOperationFailed) {
        }

        self::assertTrue($this->audit->isEmpty());
    }
    public function testASubdomainWhoseUserNamesAreAlreadyInUseIsRefused(): void
    {
        // `info.kunde@example.test` in the dotted scheme is the Cyrus user
        // `info.kunde.example.test` — exactly what `info@kunde.example.test`
        // would get. Creating the subdomain would make that login ambiguous
        // (U-96; the other direction is U-86).
        $this->store->given(DomainFixture::domain('example.test', prefix: 'example'), 'info.kunde.example.test');

        $failure = $this->refusal(['name' => 'kunde.example.test']);

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertSame([], $this->store->operations());
        self::assertTrue($this->audit->isEmpty());
    }

    public function testASubdomainThatAMailingListKeyAlreadyReachesIsRefused(): void
    {
        // U-145: the list `info.kunde@example.test` has the key
        // `info.kunde.example.test`. Once `kunde.example.test` exists, its
        // account `info` would get that Cyrus name, and in alias mode the
        // aliases file sends it to the list.
        $this->store->given(DomainFixture::domain('example.test', prefix: 'example'));
        $this->store->givenListAddress('Info.Kunde@example.test');

        $failure = $this->refusal(['name' => 'kunde.example.test']);

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertSame([], $this->store->operations());
    }

    public function testAMailingListOfTheSubdomainItselfIsNoClaim(): void
    {
        // A leftover `lists` row in the domain being created is not in a parent
        // domain. Its key is what the account `team` of the new domain would
        // get, and account creation refuses that name (U-145); the domain
        // itself is free.
        $this->store->given(DomainFixture::domain('example.test', prefix: 'example'));
        $this->store->givenListAddress('team@kunde.example.test');

        $this->createDomain()->handle(DomainFixture::superuser(), DomainDraft::fromRequest(['name' => 'kunde.example.test']));

        self::assertNotNull($this->store->find(DomainFixture::name('kunde.example.test')));
    }

    public function testTheCollisionIsFoundWhateverTheSpelling(): void
    {
        $this->store->given(DomainFixture::domain('example.test', prefix: 'example'), 'Info.Kunde.EXAMPLE.test');

        self::assertSame(
            DomainErrorCode::NAMESPACE_CONFLICT,
            $this->refusal(['name' => 'kunde.example.test'])->errorCode,
        );
    }

    public function testADeeperSubdomainIsRefusedToo(): void
    {
        $this->store->given(DomainFixture::domain('example.test', prefix: 'example'), 'a.b.c.example.test');

        self::assertSame(
            DomainErrorCode::NAMESPACE_CONFLICT,
            $this->refusal(['name' => 'b.c.example.test'])->errorCode,
        );
    }

    public function testAFullyQualifiedUserNameIsNotAmbiguous(): void
    {
        $this->store->given(DomainFixture::domain('example.test', prefix: 'example'), 'info.kunde@example.test');

        $summary = $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'kunde.example.test']),
        );

        self::assertSame('kunde.example.test', $summary->name()->toString());
    }

    public function testAParentDomainIsNotBlockedByTheAccountsOfItsSubdomain(): void
    {
        // The account belongs to the subdomain; a parent created later cannot
        // take the name, because U-86 refuses `info.kunde@example.test` then.
        $this->store->given(DomainFixture::domain('kunde.example.test', prefix: 'kunde'), 'info.kunde.example.test');

        $summary = $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'example.test']),
        );

        self::assertSame('example.test', $summary->name()->toString());
    }

    public function testANameThatOnlyEndsTheSameWayIsNotASubdomain(): void
    {
        $this->store->given(DomainFixture::domain('example.test', prefix: 'example'), 'info.xkunde.example.test');

        $summary = $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'kunde.example.test']),
        );

        self::assertSame('kunde.example.test', $summary->name()->toString());
    }

    public function testTheChecksAreRepeatedUnderTheNamespaceLock(): void
    {
        // An account creation in example.test that got between the courtesy
        // check and the lock (U-97).
        $this->store->given(DomainFixture::domain('example.test', prefix: 'example'));
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->given(DomainFixture::domain('example.test', prefix: 'example'), 'info.kunde.example.test');
        });

        $failure = $this->refusal(['name' => 'kunde.example.test']);

        self::assertSame(DomainErrorCode::NAMESPACE_CONFLICT, $failure->errorCode);
        self::assertFalse($this->store->exists(DomainFixture::name('kunde.example.test')));
        self::assertSame(1, $this->store->namespaceLocksTaken());
    }

    public function testANameTakenBeforeTheLockIsStillAConflict(): void
    {
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->given(DomainFixture::domain('neu.test'));
        });

        self::assertSame(DomainErrorCode::NAME_TAKEN, $this->refusal(['name' => 'neu.test'])->errorCode);
    }

    public function testLeftoverAssignmentsOfAnEarlierDomainRefuseTheName(): void
    {
        // U-99: an assignment that survived its domain would hand the new one
        // to the old domain master.
        $this->store->givenAssignment('master', 'Kunde.Test');

        $failure = $this->refusal(['name' => 'kunde.test']);

        self::assertSame(DomainErrorCode::LEFTOVER_STATE, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertFalse($this->store->exists(DomainFixture::name('kunde.test')));
        self::assertTrue($this->audit->isEmpty());
    }

    public function testLeftoverAddressesOfAnEarlierDomainRefuseTheName(): void
    {
        // A catchall would deliver the new customer's mail to the old mailbox.
        $this->store->givenAlias('@kunde.test');

        self::assertSame(DomainErrorCode::LEFTOVER_STATE, $this->refusal(['name' => 'kunde.test'])->errorCode);
    }

    public function testLeftoversWrittenBeforeTheLockAreSeenUnderIt(): void
    {
        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->givenAlias('info@kunde.test');
        });

        self::assertSame(DomainErrorCode::LEFTOVER_STATE, $this->refusal(['name' => 'kunde.test'])->errorCode);
        self::assertFalse($this->store->exists(DomainFixture::name('kunde.test')));
    }

    public function testLeftoversOfAnotherNameDoNotMatter(): void
    {
        $this->store->givenAssignment('master', 'other.test');
        $this->store->givenAlias('info@sub.kunde.test');

        $summary = $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'kunde.test']),
        );

        self::assertSame('kunde.test', $summary->name()->toString());
    }


    /**
     * @return iterable<string, array{string}>
     */
    public static function transportDomainSpellings(): iterable
    {
        yield 'as configured' => ['maillist.invalid'];
        yield 'upper case' => ['MailList.Invalid'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transportDomainSpellings')]
    public function testTheListTransportDomainCannotBeAManagedDomain(string $name): void
    {
        try {
            $this->createDomain()->handle(DomainFixture::superuser(), DomainDraft::fromRequest(['name' => $name]));
            self::fail('The transport domain was created as a domain.');
        } catch (\Mailadmin\Domain\Exception\InvalidAddress $refused) {
            self::assertSame('domain.invalid_name', $refused->code());
        }

        self::assertSame([], $this->store->operations());
    }
}

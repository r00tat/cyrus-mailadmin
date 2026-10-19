<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Domain;

use Mailadmin\Application\Domain\DeleteDomain;
use Mailadmin\Application\Domain\DomainDraft;
use Mailadmin\Application\Domain\DomainErrorCode;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Tests\Support\DomainFixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Deleting a domain, and — mostly — not deleting one.
 *
 * web-cyradm cascaded: one dialog removed the domain, its accounts and the mail
 * in their mailboxes. U-49 refuses instead.
 */
#[CoversClass(DeleteDomain::class)]
final class DeleteDomainTest extends DomainUseCaseTestCase
{
    public function testAnEmptyDomainGoes(): void
    {
        $this->store->given(DomainFixture::domain('leer.test'));

        $this->deleteDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('leer.test'),
            $this->currentEtag('leer.test'),
        );

        self::assertFalse($this->store->exists(DomainFixture::name('leer.test')));
        self::assertSame(['delete leer.test'], $this->store->operations());
    }

    public function testNothingIsAskedOfTheMailStore(): void
    {
        // A domain has no mailbox of its own, and an empty one has no accounts
        // either. Nothing to order, nothing to compensate.
        $this->store->given(DomainFixture::domain('leer.test'));
        $this->mailstore->makeUnreachable();

        $this->deleteDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('leer.test'),
            $this->currentEtag('leer.test'),
        );

        self::assertSame([], $this->mailstore->operations());
    }

    public function testADomainWithAccountsIsRefusedRatherThanCascaded(): void
    {
        $this->store->given(DomainFixture::domain('voll.test'), 'anna.voll.test');

        $failure = $this->refusal('voll.test', $this->currentEtag('voll.test'));

        self::assertSame(DomainErrorCode::NOT_EMPTY, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertTrue($this->store->exists(DomainFixture::name('voll.test')));
        self::assertSame([], $this->store->operations());
    }

    public function testAMissingIfMatchIsRefusedBeforeTheEmptinessCheck(): void
    {
        // The point of the header here is that the account count the client saw
        // is the count that decides (U-49). Checking emptiness first would make
        // the header decorative.
        $this->store->given(DomainFixture::domain('voll.test'), 'anna.voll.test');

        $failure = $this->refusal('voll.test', null);

        self::assertSame(DomainErrorCode::PRECONDITION_REQUIRED, $failure->errorCode);
        self::assertSame(428, $failure->status);
    }

    public function testAnEtagFromBeforeAnAccountArrivedIsRefused(): void
    {
        $this->store->given(DomainFixture::domain('leer.test'));
        $stale = $this->currentEtag('leer.test');

        // Somebody adds an account between the client's GET and its DELETE.
        $this->store->given(DomainFixture::domain('leer.test'), 'anna.leer.test');

        $failure = $this->refusal('leer.test', $stale);

        self::assertSame(DomainErrorCode::PRECONDITION_FAILED, $failure->errorCode);
        self::assertSame(412, $failure->status);
        self::assertTrue($this->store->exists(DomainFixture::name('leer.test')));
    }

    public function testADomainThatIsNotThereIsAPlainNotFound(): void
    {
        $failure = $this->refusal('weg.test', '*');

        self::assertSame(404, $failure->status);
        self::assertSame(DomainErrorCode::NOT_FOUND, $failure->errorCode);
    }

    public function testADomainMasterMayNotDelete(): void
    {
        $this->store->given(DomainFixture::domain('example.test'));

        $this->expectException(AccessDenied::class);

        $this->deleteDomain()->handle(
            DomainFixture::domainMaster('anna', 'example.test'),
            DomainFixture::name('example.test'),
            '*',
        );
    }

    private function refusal(string $name, ?string $ifMatch): DomainOperationFailed
    {
        try {
            $this->deleteDomain()->handle(
                DomainFixture::superuser(),
                DomainFixture::name($name),
                $ifMatch,
            );
        } catch (DomainOperationFailed $failure) {
            return $failure;
        }

        self::fail('The deletion was expected to be refused.');
    }

    public function testTheAuditEntryRecordsTheStateThatWasRemoved(): void
    {
        $this->store->given(DomainFixture::domain('leer.test'));

        $this->deleteDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('leer.test'),
            $this->currentEtag('leer.test'),
        );

        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('domain.delete', $entries[0]->action);
        self::assertSame('leer.test', $entries[0]->objectId);
        self::assertSame('leer.test', $entries[0]->before['name'] ?? null);
        self::assertNull($entries[0]->after, 'A deletion has no following state (U-61).');
    }

    public function testARefusedDeletionWritesNoEntry(): void
    {
        $this->store->given(DomainFixture::domain('voll.test'), 'anna.voll.test');

        try {
            $this->deleteDomain()->handle(
                DomainFixture::superuser(),
                DomainFixture::name('voll.test'),
                $this->currentEtag('voll.test'),
            );
        } catch (DomainOperationFailed) {
        }

        self::assertTrue($this->audit->isEmpty());
    }
    public function testADomainWithAddressesIsRefusedLikeOneWithAccounts(): void
    {
        // An alias that survives its domain comes back to life when the name is
        // created again — with whatever destination it had (U-95).
        $this->store->given(DomainFixture::domain('leer.test'));
        $this->store->givenAlias('info@leer.test');

        $failure = $this->refusal('leer.test', $this->currentEtag('leer.test'));

        self::assertSame(DomainErrorCode::STILL_HAS_ADDRESSES, $failure->errorCode);
        self::assertSame(409, $failure->status);
        self::assertTrue($this->store->exists(DomainFixture::name('leer.test')));
        self::assertSame([], $this->store->operations());
        self::assertTrue($this->audit->isEmpty());
    }

    public function testACatchallCountsAsAnAddress(): void
    {
        $this->store->given(DomainFixture::domain('leer.test'));
        $this->store->givenAlias('@Leer.TEST');

        $failure = $this->refusal('leer.test', $this->currentEtag('leer.test'));

        self::assertSame(DomainErrorCode::STILL_HAS_ADDRESSES, $failure->errorCode);
    }

    public function testAddressesOfOtherDomainsDoNotBlock(): void
    {
        // Suffix `@leer.test`, not `leer.test`: a subdomain and a longer name
        // that merely ends the same way are somebody else's.
        $this->store->given(DomainFixture::domain('leer.test'));
        $this->store->givenAlias('info@sub.leer.test');
        $this->store->givenAlias('info@nichtleer.test');
        $this->store->givenAlias('leer.test');

        $this->deleteDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('leer.test'),
            $this->currentEtag('leer.test'),
        );

        self::assertFalse($this->store->exists(DomainFixture::name('leer.test')));
    }

    public function testTheAssignmentsOfTheDomainGoWithIt(): void
    {
        // Otherwise a domain created again under the same name hands the old
        // domain master their access back, silently (U-95).
        $this->store->given(DomainFixture::domain('leer.test'));
        $this->store->givenAssignment('anna', 'leer.test');
        $this->store->givenAssignment('bert', 'Leer.Test');
        $this->store->givenAssignment('bert', 'other.test');
        $this->store->givenAssignment('chef', '*');

        $this->deleteDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('leer.test'),
            $this->currentEtag('leer.test'),
        );

        self::assertSame(
            [['admin' => 'bert', 'domain' => 'other.test'], ['admin' => 'chef', 'domain' => '*']],
            $this->store->assignments(),
        );
    }

    public function testARecreatedDomainDoesNotInheritTheOldAssignments(): void
    {
        $this->store->given(DomainFixture::domain('leer.test'));
        $this->store->givenAssignment('anna', 'leer.test');

        $this->deleteDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('leer.test'),
            $this->currentEtag('leer.test'),
        );
        $this->createDomain()->handle(
            DomainFixture::superuser(),
            DomainDraft::fromRequest(['name' => 'leer.test']),
        );

        self::assertSame([], $this->store->assignments());
    }

    public function testTheRemovedAssignmentsAreChildEntriesOfTheDeletion(): void
    {
        $this->store->given(DomainFixture::domain('leer.test'));
        $this->store->givenAssignment('anna', 'leer.test');
        $this->store->givenAssignment('bert', 'leer.test');

        $this->deleteDomain()->handle(
            DomainFixture::superuser(),
            DomainFixture::name('leer.test'),
            $this->currentEtag('leer.test'),
        );

        $entries = $this->audit->all();

        self::assertCount(3, $entries);
        self::assertSame('domain', $entries[0]->objectType);
        self::assertNull($entries[0]->parentId);

        foreach ([1 => 'anna', 2 => 'bert'] as $index => $admin) {
            self::assertSame('domain.delete', $entries[$index]->action);
            self::assertSame('admin', $entries[$index]->objectType);
            self::assertSame($admin, $entries[$index]->objectId);
            self::assertSame(['domains' => ['leer.test']], $entries[$index]->before);
            self::assertSame($entries[0]->id, $entries[$index]->parentId);
        }
    }

    public function testAnAccountThatArrivesBeforeTheLockStopsTheDeletion(): void
    {
        // The emptiness check is repeated under the namespace lock, which
        // account creation takes for its database step (U-97).
        $this->store->given(DomainFixture::domain('leer.test'));
        $etag = $this->currentEtag('leer.test');

        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->given(DomainFixture::domain('leer.test'), 'anna.leer.test');
        });

        $failure = $this->refusal('leer.test', $etag);

        self::assertSame(DomainErrorCode::NOT_EMPTY, $failure->errorCode);
        self::assertTrue($this->store->exists(DomainFixture::name('leer.test')));
        self::assertSame(1, $this->store->namespaceLocksTaken());
    }

    public function testAnAddressThatArrivesBeforeTheLockStopsTheDeletion(): void
    {
        $this->store->given(DomainFixture::domain('leer.test'));
        $etag = $this->currentEtag('leer.test');

        $this->store->beforeNamespaceLockedWork(function (): void {
            $this->store->givenAlias('info@leer.test');
        });

        $failure = $this->refusal('leer.test', $etag);

        self::assertSame(DomainErrorCode::STILL_HAS_ADDRESSES, $failure->errorCode);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Address;

use Mailadmin\Application\Address\AddressErrorCode;
use Mailadmin\Application\Address\AddressView;
use Mailadmin\Application\Address\CatchallChange;
use Mailadmin\Application\Address\DeleteCatchall;
use Mailadmin\Application\Address\DeleteDomainAddress;
use Mailadmin\Application\Address\EntryKey;
use Mailadmin\Application\Address\ListDomainAddresses;
use Mailadmin\Application\Address\SetCatchall;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\Virtual\VirtualEntryType;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ListDomainAddresses::class)]
#[CoversClass(DeleteDomainAddress::class)]
#[CoversClass(SetCatchall::class)]
#[CoversClass(DeleteCatchall::class)]
#[CoversClass(\Mailadmin\Application\Address\GetCatchall::class)]
#[CoversClass(EntryKey::class)]
final class DomainAddressesUseCaseTest extends AddressUseCaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->givenAccount('anna.example.test');
        $this->givenAccount('ben.example.test');
    }

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    public function testEveryRowOfTheDomainIsListedWithItsTypeUnknownRowsIncluded(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('@example.test', 'anna.example.test', '');
        $this->virtual->givenRow('anna.example.test', 'extern@elsewhere.test', '');
        $this->virtual->givenRow('broken@example.test', '', 'anna.example.test');
        $this->virtual->givenRow('info@other.test', 'extern@elsewhere.test', 'other.test');

        $listing = $this->listAddresses()->handle(DomainFixture::superuser(), self::domain(), new ListCriteria(sort: 'type'));

        $types = array_map(
            static fn ($entry): string => $entry->type()->value . ' ' . $entry->alias(),
            $listing->page->items,
        );

        self::assertSame([
            'alias info@example.test',
            'catchall @example.test',
            'forward anna.example.test',
            'mailbox_address anna@example.test',
            'mailbox_address ben@example.test',
            'unknown broken@example.test',
        ], $types);
        self::assertSame(6, $listing->page->total);

        $unknown = AddressView::entries($listing->rows);
        $byAlias = array_column($unknown, null, 'alias');
        self::assertSame('no_destination', $byAlias['broken@example.test']['unknown_reason']);
        self::assertSame('anna.example.test', $byAlias['info@example.test']['sender_account']);
    }

    public function testTheListingFiltersAndPagesInTheUseCase(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->virtual->givenRow($name . '@example.test', 'anna.example.test', 'example.test');
        }

        $page = $this->listAddresses()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new ListCriteria(page: 2, perPage: 2, order: SortDirection::Descending, filters: ['type' => 'alias']),
        )->page;

        self::assertSame(4, $page->total);
        self::assertSame(['b@example.test', 'a@example.test'], array_map(static fn ($e): string => $e->alias(), $page->items));

        $filtered = $this->listAddresses()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new ListCriteria(filters: ['alias' => 'C@']),
        )->page;
        self::assertSame(['c@example.test'], array_map(static fn ($e): string => $e->alias(), $filtered->items));
    }

    public function testAnUnknownSortOrFilterIsACriteriaError(): void
    {
        $this->expectException(CriteriaError::class);
        $this->listAddresses()->handle(DomainFixture::superuser(), self::domain(), new ListCriteria(sort: 'dest'));
    }

    public function testAMissingDomainIsNotFoundAndAForeignOneNotVisible(): void
    {
        $failure = $this->expectFailure(fn () => $this->listAddresses()->handle(
            DomainFixture::superuser(),
            self::domain('nowhere.test'),
            new ListCriteria(),
        ));
        self::assertSame(404, $failure->status);

        $this->expectException(ResourceNotVisible::class);
        $this->listAddresses()->handle(DomainFixture::domainMaster('mia', 'other.test'), self::domain(), new ListCriteria());
    }

    public function testLeftoverRowsOfADeletedDomainAreStillListed(): void
    {
        // The domain row is gone, the addresses are not — exactly what
        // `domain.leftover_state` asks the operator to remove.
        $this->virtual->givenRow('info@gone.test', 'extern@elsewhere.test', 'gone.test');

        $listing = $this->listAddresses()->handle(DomainFixture::superuser(), self::domain('gone.test'), new ListCriteria());

        self::assertSame(1, $listing->page->total);
    }

    // -----------------------------------------------------------------
    // Removing one row
    // -----------------------------------------------------------------

    public function testOneRowIsRemovedByItsKey(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $doomed = $this->virtual->givenRow('info@example.test', 'ben.example.test', 'ben.example.test');

        $this->deleteEntry()->handle(DomainFixture::superuser(), self::domain(), EntryKey::of($doomed), false);

        self::assertSame(['info@example.test|anna.example.test|anna.example.test|1'], $this->rowsOf('info@example.test'));
        self::assertCount(1, $this->audit->withAction(DeleteDomainAddress::OPERATION));
    }

    public function testAnUnknownRowCanBeRemoved(): void
    {
        $broken = $this->virtual->givenRow('broken@example.test', '', 'anna.example.test');

        $this->deleteEntry()->handle(DomainFixture::superuser(), self::domain(), EntryKey::of($broken), false);

        self::assertSame([], $this->rowsOf('broken@example.test'));
    }

    public function testAMailboxAddressIsNotRemovedHere(): void
    {
        $row = $this->virtual->rowsWithAlias('anna@example.test')[0];

        $failure = $this->expectFailure(fn () => $this->deleteEntry()->handle(
            DomainFixture::superuser(),
            self::domain(),
            EntryKey::of($row),
            false,
        ));

        self::assertSame(AddressErrorCode::NOT_EDITABLE, $failure->errorCode);
    }

    public function testAKeyOfAnotherDomainOrAMalformedOneIsNotFound(): void
    {
        $foreign = $this->virtual->givenRow('info@other.test', 'extern@elsewhere.test', 'other.test');

        foreach ([EntryKey::of($foreign), 'zz', str_repeat('0', 32)] as $key) {
            $failure = $this->expectFailure(fn () => $this->deleteEntry()->handle(
                DomainFixture::superuser(),
                self::domain(),
                $key,
                false,
            ));
            self::assertSame(404, $failure->status, $key);
        }

        self::assertCount(1, $this->rowsOf('info@other.test'));
    }

    public function testRemovingAnEnabledPostmasterRowNeedsConfirmation(): void
    {
        $row = $this->virtual->givenRow('postmaster@example.test', 'anna.example.test', 'anna.example.test');

        $failure = $this->expectFailure(fn () => $this->deleteEntry()->handle(
            DomainFixture::superuser(),
            self::domain(),
            EntryKey::of($row),
            false,
        ));
        self::assertSame(AddressErrorCode::RESERVED, $failure->errorCode);

        $this->deleteEntry()->handle(DomainFixture::superuser(), self::domain(), EntryKey::of($row), true);
        self::assertSame([], $this->rowsOf('postmaster@example.test'));
    }

    // -----------------------------------------------------------------
    // Bare keys (U-126)
    // -----------------------------------------------------------------

    public function testABareKeyOfAnotherDomainsAccountBelongsToThatDomainNotToTheSender(): void
    {
        // `bob.other.test` is the lookup key of an account in other.test; the
        // row merely names an account of example.test as its sender.
        $this->givenAccount('bob.other.test', 'other.test');
        $row = $this->virtual->givenRow('bob.other.test', 'extern@elsewhere.test', 'anna.example.test');
        $variant = $this->virtual->givenRow('BOB.other.test', 'extern@elsewhere.test', 'anna.example.test');

        $ours = $this->listAddresses()->handle(DomainFixture::domainMaster('mia', 'example.test'), self::domain(), new ListCriteria());
        $aliases = array_map(static fn ($entry): string => $entry->alias(), $ours->page->items);
        self::assertNotContains('bob.other.test', $aliases);
        self::assertNotContains('BOB.other.test', $aliases);

        $theirs = $this->listAddresses()->handle(DomainFixture::superuser(), self::domain('other.test'), new ListCriteria(filters: ['alias' => 'bob.']));
        self::assertSame(
            ['BOB.other.test', 'bob.other.test'],
            array_map(static fn ($entry): string => $entry->alias(), $theirs->page->items),
            'shown in the domain the key belongs to, not skipped',
        );

        foreach ([$row, $variant] as $candidate) {
            $failure = $this->expectFailure(fn () => $this->deleteEntry()->handle(
                DomainFixture::domainMaster('mia', 'example.test'),
                self::domain(),
                EntryKey::of($candidate),
                false,
            ));
            self::assertSame(404, $failure->status);
        }

        self::assertCount(2, $this->rowsOf('bob.other.test'), 'the foreign key keeps working');

        $this->deleteEntry()->handle(DomainFixture::domainMaster('olga', 'other.test'), self::domain('other.test'), EntryKey::of($row), false);
        self::assertSame(['BOB.other.test|extern@elsewhere.test|anna.example.test|1'], $this->rowsOf('bob.other.test'));
    }

    public function testABareKeyThatNamesNoAccountIsListedButOnlyASuperuserRemovesIt(): void
    {
        $row = $this->virtual->givenRow('send-only', 'extern@elsewhere.test', 'anna.example.test');

        $listing = $this->listAddresses()->handle(DomainFixture::domainMaster('mia', 'example.test'), self::domain(), new ListCriteria(filters: ['alias' => 'send-only']));
        self::assertSame(1, $listing->page->total, 'unknown rows are shown, not skipped');

        $failure = $this->expectFailure(fn () => $this->deleteEntry()->handle(
            DomainFixture::domainMaster('mia', 'example.test'),
            self::domain(),
            EntryKey::of($row),
            false,
        ));
        self::assertSame(AddressErrorCode::NOT_EDITABLE, $failure->errorCode);
        self::assertCount(1, $this->rowsOf('send-only'));

        $this->deleteEntry()->handle(DomainFixture::superuser(), self::domain(), EntryKey::of($row), false);
        self::assertSame([], $this->rowsOf('send-only'));
    }

    public function testABareKeyOfAnAccountInTwoDomainsNeedsBothInScope(): void
    {
        // Two stored names that differ only in case, in two domains: Postfix
        // looks the key up without regard to case, so it routes for both.
        $this->givenAccount('x.shared', 'example.test', withAddressRow: false);
        $this->givenAccount('X.SHARED', 'other.test', withAddressRow: false);
        $row = $this->virtual->givenRow('x.shared', 'extern@elsewhere.test', '');

        self::assertSame(1, $this->listAddresses()->handle(DomainFixture::superuser(), self::domain('other.test'), new ListCriteria(filters: ['alias' => 'x.shared']))->page->total);

        try {
            $this->deleteEntry()->handle(DomainFixture::domainMaster('mia', 'example.test'), self::domain(), EntryKey::of($row), false);
            self::fail('A key that also belongs to a foreign domain was removed.');
        } catch (ResourceNotVisible) {
        }

        self::assertCount(1, $this->rowsOf('x.shared'));

        $this->deleteEntry()->handle(DomainFixture::domainMaster('mia', 'example.test', 'other.test'), self::domain(), EntryKey::of($row), false);
        self::assertSame([], $this->rowsOf('x.shared'));
    }

    public function testTheKeyIsAFingerprintOfAllFourFields(): void
    {
        $one = new VirtualRow('info@example.test', 'a@x.test', 'u', 1);

        self::assertTrue(EntryKey::isWellFormed(EntryKey::of($one)));
        self::assertSame(EntryKey::of($one), EntryKey::of(new VirtualRow('info@example.test', 'a@x.test', 'u', 1)));
        self::assertNotSame(EntryKey::of($one), EntryKey::of(new VirtualRow('info@example.test', 'a@x.test', 'u', 0)));
        self::assertNotSame(EntryKey::of($one), EntryKey::of(new VirtualRow('info@example.test', 'a@x.tes', 'tu', 1)));
        self::assertFalse(EntryKey::isWellFormed('ABCDEF'));
    }

    // -----------------------------------------------------------------
    // Catchall
    // -----------------------------------------------------------------

    public function testACatchallIsWrittenWithoutSenderByDefault(): void
    {
        $state = $this->setCatchall()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new CatchallChange(['anna.example.test']),
            null,
        );

        self::assertSame(['@example.test|anna.example.test||1'], $this->rowsOf('@example.test'));
        self::assertTrue($state->isConfigured());
        self::assertNull($state->senderAccount());
        self::assertCount(1, $this->audit->withAction(SetCatchall::OPERATION));
    }

    public function testACatchallMayNameASender(): void
    {
        $state = $this->setCatchall()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new CatchallChange(['anna.example.test'], senderAccountGiven: true, senderAccount: 'anna.example.test'),
            null,
        );

        self::assertSame('anna.example.test', $state->senderAccount());
    }

    public function testACatchallIntoAnAddressOfItsOwnDomainThatDoesNotExistIsALoop(): void
    {
        $this->expectException(InvalidAddress::class);
        $this->setCatchall()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new CatchallChange(['nobody@example.test']),
            null,
        );
    }

    public function testACatchallIntoADisabledAddressOfItsOwnDomainIsRefused(): void
    {
        // Review finding on A3: Postfix does not read the disabled row, so
        // old@ falls back onto the catchall itself.
        $this->virtual->givenRow('old@example.test', 'anna.example.test', 'anna.example.test', 0);

        $this->expectException(InvalidAddress::class);
        $this->setCatchall()->handle(DomainFixture::superuser(), self::domain(), new CatchallChange(['old@example.test']), null);
    }

    public function testRemovingTheRowTheCatchallDependsOnIsRefused(): void
    {
        $row = $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('@example.test', 'info@example.test', '');

        $failure = $this->expectFailure(fn () => $this->deleteEntry()->handle(DomainFixture::superuser(), self::domain(), EntryKey::of($row), false));

        self::assertSame(AddressErrorCode::NEEDED_BY_CATCHALL, $failure->errorCode);
        self::assertCount(1, $this->rowsOf('info@example.test'));
    }

    public function testACatchallIntoAnExistingAddressOfItsOwnDomainIsFine(): void
    {
        $this->setCatchall()->handle(DomainFixture::superuser(), self::domain(), new CatchallChange(['anna@example.test']), null);

        self::assertSame(['@example.test|anna@example.test||1'], $this->rowsOf('@example.test'));
    }

    public function testReplacingACatchallKeepsTheStoredSpellingOfItsAlias(): void
    {
        $this->virtual->givenRow('@Example.test', 'anna.example.test', '');

        $this->setCatchall()->handle(DomainFixture::superuser(), self::domain(), new CatchallChange(['ben.example.test']), null);

        self::assertSame(['@Example.test|ben.example.test||1'], $this->rowsOf('@example.test'));
    }

    public function testACatchallOfAForeignDomainIsNotVisible(): void
    {
        $this->expectException(ResourceNotVisible::class);
        $this->setCatchall()->handle(
            DomainFixture::domainMaster('mia', 'other.test'),
            self::domain(),
            new CatchallChange(['anna.example.test']),
            null,
        );
    }

    public function testDeletingTheCatchallIsIdempotent(): void
    {
        $this->virtual->givenRow('@example.test', 'anna.example.test', '');

        $this->deleteCatchall()->handle(DomainFixture::superuser(), self::domain(), null);
        $this->deleteCatchall()->handle(DomainFixture::superuser(), self::domain(), null);

        self::assertSame([], $this->rowsOf('@example.test'));
        self::assertCount(1, $this->audit->withAction(DeleteCatchall::OPERATION));
        self::assertFalse($this->getCatchall()->handle(DomainFixture::superuser(), self::domain())->isConfigured());
    }

    public function testACatchallChangeWithoutItsAuditEntryIsSetBack(): void
    {
        $this->virtual->givenRow('@example.test', 'anna.example.test', '');
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->setCatchall()->handle(DomainFixture::superuser(), self::domain(), new CatchallChange(['ben.example.test']), null);
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertSame(['@example.test|anna.example.test||1'], $this->rowsOf('@example.test'));
    }

    public function testTheTypeFilterTakesOnlyKnownTypes(): void
    {
        $this->virtual->givenRow('@example.test', 'anna.example.test', '');

        $page = $this->listAddresses()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new ListCriteria(filters: ['type' => VirtualEntryType::CatchAll->value]),
        )->page;
        self::assertSame(1, $page->total);

        $this->expectException(CriteriaError::class);
        $this->listAddresses()->handle(DomainFixture::superuser(), self::domain(), new ListCriteria(filters: ['type' => 'bogus']));
    }
}

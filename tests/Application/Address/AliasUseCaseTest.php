<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Address;

use Mailadmin\Application\Address\AddressErrorCode;
use Mailadmin\Application\Address\AddressView;
use Mailadmin\Application\Address\AliasChange;
use Mailadmin\Application\Address\AliasDraft;
use Mailadmin\Application\Address\CreateAlias;
use Mailadmin\Application\Address\DeleteAlias;
use Mailadmin\Application\Address\UpdateAlias;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\RefusingAuditWriter;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CreateAlias::class)]
#[CoversClass(UpdateAlias::class)]
#[CoversClass(DeleteAlias::class)]
#[CoversClass(\Mailadmin\Application\Address\GetAlias::class)]
#[CoversClass(\Mailadmin\Application\Address\RowWriter::class)]
#[CoversClass(\Mailadmin\Application\Address\SenderRules::class)]
#[CoversClass(\Mailadmin\Application\Address\DestinationRules::class)]
final class AliasUseCaseTest extends AddressUseCaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->givenAccount('anna.example.test');
        $this->givenAccount('ben.example.test');
    }

    // -----------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------

    public function testAnAliasToOneAccountLetsThatAccountSendAsIt(): void
    {
        $state = $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new AliasDraft('Info', ['Anna.Example.Test']),
        );

        // Lower-cased address, stored spelling of the account, the account as sender (U-104, U-106).
        self::assertSame(['info@example.test|anna.example.test|anna.example.test|1'], $this->rowsOf('info@example.test'));
        self::assertSame('anna.example.test', $state->senderAccount());
        self::assertCount(1, $this->audit->withAction(CreateAlias::OPERATION));
        self::assertSame(1, $this->domains->namespaceLocksTaken());
    }

    public function testAnAliasToSeveralDestinationsHasNoSenderButThePlaceholder(): void
    {
        $state = $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new AliasDraft('team', ['anna.example.test', 'ben.example.test', 'extern@elsewhere.test']),
        );

        self::assertSame(
            ['team@example.test|anna.example.test,ben.example.test,extern@elsewhere.test|example.test|1'],
            $this->rowsOf('team@example.test'),
        );
        self::assertNull($state->senderAccount());
    }

    public function testAnExplicitSenderMustBeOneOfTheDestinations(): void
    {
        $state = $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new AliasDraft('team', ['anna.example.test', 'ben.example.test'], 'BEN.example.test'),
        );
        self::assertSame('ben.example.test', $state->senderAccount());

        $this->expectException(InvalidAddress::class);
        $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new AliasDraft('sales', ['anna.example.test'], 'ben.example.test'),
        );
    }

    public function testATakenAddressIsRefused(): void
    {
        $failure = $this->expectFailure(fn () => $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new AliasDraft('anna', ['ben.example.test']),
        ));

        self::assertSame(AddressErrorCode::TAKEN, $failure->errorCode);
        self::assertSame(409, $failure->status);
    }

    public function testADisabledLegacyRowStillTakesTheAddress(): void
    {
        $this->virtual->givenRow('old@example.test', 'anna.example.test', 'anna.example.test', 0);

        $failure = $this->expectFailure(fn () => $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new AliasDraft('old', ['ben.example.test']),
        ));

        self::assertSame(AddressErrorCode::TAKEN, $failure->errorCode);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function badDestinations(): iterable
    {
        yield 'a loop onto itself' => [['info@example.test']];
        yield 'a duplicate' => [['anna.example.test', 'ANNA.example.test']];
        yield 'a bare name that is no account' => [['nobody']];
        yield 'a comma inside a value' => [['a@x.test,b@x.test']];
        yield 'whitespace inside a value' => [['a b@x.test']];
    }

    /**
     * @param list<string> $destinations
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badDestinations')]
    public function testUnusableDestinationsAreRefused(array $destinations): void
    {
        try {
            $this->createAlias()->handle(DomainFixture::superuser(), self::domain(), new AliasDraft('info', $destinations));
            self::fail('expected a refusal');
        } catch (\Mailadmin\Domain\Exception\DomainError) {
            self::assertSame([], $this->rowsOf('info@example.test'));
        }
    }

    public function testMoreThanFiftyDestinationsAreRefused(): void
    {
        $many = array_map(static fn (int $i): string => sprintf('d%d@elsewhere.test', $i), range(1, 51));

        $this->expectException(InvalidAddress::class);
        $this->createAlias()->handle(DomainFixture::superuser(), self::domain(), new AliasDraft('info', $many));
    }

    public function testAnAliasInAnUnknownDomainIsNotFound(): void
    {
        $failure = $this->expectFailure(fn () => $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain('nowhere.test'),
            new AliasDraft('info', ['anna.example.test']),
        ));

        self::assertSame(404, $failure->status);
    }

    public function testAForeignDomainIsNotVisible(): void
    {
        $this->expectException(ResourceNotVisible::class);
        $this->createAlias()->handle(
            DomainFixture::domainMaster('mia', 'other.test'),
            self::domain(),
            new AliasDraft('info', ['anna.example.test']),
        );
    }

    public function testAPlaceholderThatIsAnAccountNameIsRefused(): void
    {
        // An account whose stored name equals the domain name: writing the
        // placeholder would let it send as the alias.
        $this->givenAccount('example.test', 'example.test', false);

        $failure = $this->expectFailure(fn () => $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new AliasDraft('team', ['anna.example.test', 'ben.example.test']),
        ));

        self::assertSame(AddressErrorCode::UNSTORABLE, $failure->errorCode);
    }

    public function testACreationWithoutItsAuditEntryIsTakenBack(): void
    {
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->createAlias()->handle(DomainFixture::superuser(), self::domain(), new AliasDraft('info', ['anna.example.test']));
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertSame([], $this->rowsOf('info@example.test'));
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testASetBackThatFailsAsWellIsARepairEntry(): void
    {
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);
        $this->virtual->failCount(PersistenceError::transactionMisuse('gone'));

        try {
            $this->createAlias()->handle(DomainFixture::superuser(), self::domain(), new AliasDraft('info', ['anna.example.test']));
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertSame(['alias.create/virtual.restore'], $this->repairs->steps());
    }

    // -----------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------

    public function testLegacyRowsSpreadOverSeveralLinesAreReadAsOneAlias(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'example.test');
        $this->virtual->givenRow('INFO@example.test', 'ben.example.test,extern@elsewhere.test', 'example.test');

        $state = $this->getAlias()->handle(DomainFixture::superuser(), 'info@example.test');

        self::assertSame(['anna.example.test', 'ben.example.test', 'extern@elsewhere.test'], $state->destinations()->all());
        self::assertTrue($state->isEditable());
        self::assertCount(2, AddressView::alias($state)['entries']);
    }

    public function testAMailboxAddressIsNoAlias(): void
    {
        $failure = $this->expectFailure(fn () => $this->getAlias()->handle(DomainFixture::superuser(), 'anna@example.test'));

        self::assertSame(404, $failure->status);
    }

    public function testAnAliasCannotPointAtTheListTransport(): void
    {
        // A domain master must not inject mail into another domain's list by
        // naming its transport key: after virtual rewriting nothing checks it.
        $failure = null;

        try {
            $this->createAlias()->handle(
                DomainFixture::domainMaster('anna', 'example.test'),
                self::domain(),
                new AliasDraft('sneak', ['team.other.test@MailList.Invalid']),
            );
        } catch (InvalidAddress $refused) {
            $failure = $refused;
        }

        self::assertNotNull($failure);
        self::assertSame('address.invalid_destination', $failure->code());
        self::assertSame([], $this->rowsOf('sneak@example.test'));
    }

    public function testAnUpdateCannotPointAnAliasAtTheListTransport(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');

        try {
            $this->updateAlias()->handle(
                DomainFixture::superuser(),
                'info@example.test',
                new AliasChange(destinations: ['anna.example.test', 'team.other.test@maillist.invalid']),
                null,
            );
            self::fail('A transport destination was accepted.');
        } catch (InvalidAddress $refused) {
            self::assertSame('address.invalid_destination', $refused->code());
        }

        self::assertSame(['info@example.test|anna.example.test|anna.example.test|1'], $this->rowsOf('info@example.test'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mailingListRows(): iterable
    {
        yield 'transport' => ['team.example.test@maillist.invalid', ''];
        yield 'legacy' => ['team.example.test', 'team.example.test'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailingListRows')]
    public function testAListAddressIsTakenForANewAlias(string $dest, string $username): void
    {
        $this->mailingLists->given(MailingList::fromStoredValues('Team', 'team', 'team@example.test', ''));
        $this->virtual->givenRow('team@example.test', $dest, $username);

        $failure = $this->expectFailure(fn () => $this->createAlias()->handle(
            DomainFixture::superuser(),
            self::domain(),
            new AliasDraft('team', ['anna.example.test']),
        ));

        self::assertSame(AddressErrorCode::TAKEN, $failure->errorCode);
        self::assertSame([sprintf('team@example.test|%s|%s|1', $dest, $username)], $this->rowsOf('team@example.test'));
    }

    public function testAMailingListIsNoAlias(): void
    {
        // The legacy list row looks like an alias onto a Cyrus user named like
        // the list; the `lists` row is what says otherwise. Before B4 it was an
        // editable alias.
        $this->mailingLists->given(MailingList::fromStoredValues('Team', 'team', 'team@example.test', ''));
        $this->virtual->givenRow('team@example.test', 'team.example.test', 'team.example.test');

        $failure = $this->expectFailure(fn () => $this->getAlias()->handle(DomainFixture::superuser(), 'team@example.test'));

        self::assertSame(404, $failure->status);
    }

    public function testAnAliasSharingItsAddressWithAMailingListEntryIsNotEditable(): void
    {
        $this->virtual->givenRow('team@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('team@example.test', 'team.example.test@maillist.invalid', '');

        $state = $this->getAlias()->handle(DomainFixture::superuser(), 'team@example.test');
        self::assertFalse(AddressView::alias($state)['editable']);
        self::assertSame(
            ['alias', 'mailing_list'],
            array_map(static fn (array $entry): mixed => $entry['type'], AddressView::alias($state)['entries']),
        );

        $failure = $this->expectFailure(fn () => $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'team@example.test',
            new AliasChange(destinations: ['ben.example.test']),
            null,
        ));

        self::assertSame(AddressErrorCode::NOT_EDITABLE, $failure->errorCode);
        self::assertCount(2, $this->rowsOf('team@example.test'));
    }

    public function testAForeignAndAMissingAliasLookTheSame(): void
    {
        $this->virtual->givenRow('info@other.test', 'extern@elsewhere.test', 'other.test');
        $actor = DomainFixture::domainMaster('anna', 'example.test');

        $this->expectException(ResourceNotVisible::class);
        $this->getAlias()->handle($actor, 'info@other.test');
    }

    // -----------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------

    public function testRowsAreRewrittenAsOneInsertFirst(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('info@example.test', 'extern@elsewhere.test', 'anna.example.test');

        $state = $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'info@example.test',
            new AliasChange(destinations: ['anna.example.test', 'ben.example.test']),
            null,
        );

        self::assertSame(
            ['info@example.test|anna.example.test,ben.example.test|anna.example.test|1'],
            $this->rowsOf('info@example.test'),
        );
        self::assertSame('anna.example.test', $state->senderAccount());
        self::assertSame(
            ['insert info@example.test', 'remove info@example.test', 'remove info@example.test'],
            $this->virtual->operations(),
        );
    }

    public function testASenderThatNoLongerReceivesTheMailLosesTheRight(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');

        $state = $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'info@example.test',
            new AliasChange(destinations: ['ben.example.test']),
            null,
        );

        self::assertNull($state->senderAccount());
        self::assertSame(['info@example.test|ben.example.test|example.test|1'], $this->rowsOf('info@example.test'));
    }

    public function testAnUnchangedAliasWritesNothing(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');

        $this->updateAlias()->handle(DomainFixture::superuser(), 'info@example.test', new AliasChange(enabled: true), null);

        self::assertSame([], $this->virtual->operations());
        self::assertTrue($this->audit->isEmpty());
    }

    public function testAStaleEtagIsAPreconditionFailure(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');

        $failure = $this->expectFailure(fn () => $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'info@example.test',
            new AliasChange(enabled: false),
            '"stale"',
        ));

        self::assertSame(412, $failure->status);
    }

    public function testRowsWithDifferentSendersAreNotEditableTogether(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('info@example.test', 'ben.example.test', 'ben.example.test');

        $failure = $this->expectFailure(fn () => $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'info@example.test',
            new AliasChange(enabled: false),
            null,
        ));

        self::assertSame(AddressErrorCode::NOT_EDITABLE, $failure->errorCode);
        self::assertCount(2, $this->rowsOf('info@example.test'));
    }

    public function testRowsWithDifferentStatusAreNotEditableTogether(): void
    {
        // Review finding on A3: merged into one row, a PATCH of the
        // destinations alone would have switched the active row off and the
        // disabled row's destination on.
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'example.test', 1);
        $this->virtual->givenRow('info@example.test', 'ben.example.test', 'example.test', 0);

        $state = $this->getAlias()->handle(DomainFixture::superuser(), 'info@example.test');
        self::assertFalse($state->isEditable());

        $failure = $this->expectFailure(fn () => $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'info@example.test',
            new AliasChange(destinations: ['anna.example.test']),
            null,
        ));

        self::assertSame(AddressErrorCode::NOT_EDITABLE, $failure->errorCode);
        self::assertSame(
            ['info@example.test|anna.example.test|example.test|1', 'info@example.test|ben.example.test|example.test|0'],
            $this->rowsOf('info@example.test'),
        );
    }

    public function testALoopThroughAnotherAliasIsRefused(): void
    {
        // Review finding on A3: only the direct self-reference was refused.
        $this->createAlias()->handle(DomainFixture::superuser(), self::domain(), new AliasDraft('a', ['b@example.test']));

        try {
            $this->createAlias()->handle(DomainFixture::superuser(), self::domain(), new AliasDraft('b', ['a@example.test']));
            self::fail('a -> b -> a was accepted');
        } catch (InvalidAddress $refused) {
            self::assertSame('a@example.test', $refused->context()['destination'] ?? null);
        }

        self::assertSame([], $this->rowsOf('b@example.test'));
    }

    public function testAnUpdateThatClosesALoopIsRefused(): void
    {
        $this->virtual->givenRow('x@example.test', 'y@example.test', 'example.test');
        $this->virtual->givenRow('y@example.test', 'z@example.test', 'example.test');
        $this->virtual->givenRow('z@example.test', 'extern@elsewhere.test', 'example.test');

        $this->expectException(InvalidAddress::class);
        $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'z@example.test',
            new AliasChange(destinations: ['x@example.test']),
            null,
        );
    }

    public function testALoopThroughTheCatchallIsRefused(): void
    {
        // nobody@ has no row: Postfix falls back to the catchall, which sends
        // the mail back to info@.
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('@example.test', 'info@example.test', '');

        $this->expectException(InvalidAddress::class);
        $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'info@example.test',
            new AliasChange(destinations: ['nobody@example.test']),
            null,
        );
    }

    public function testAChainThatEndsInAnAccountIsNoLoop(): void
    {
        $this->virtual->givenRow('x@example.test', 'anna@example.test', 'example.test');

        $this->createAlias()->handle(DomainFixture::superuser(), self::domain(), new AliasDraft('y', ['x@example.test', 'anna@example.test']));

        self::assertCount(1, $this->rowsOf('y@example.test'));
    }

    public function testDeletingOrDisablingTheCatchallsDestinationIsRefused(): void
    {
        // Review finding on A3: @example.test -> info@ would then fall back
        // onto itself and every unknown address of the domain would bounce.
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('@example.test', 'info@example.test', '');

        $failure = $this->expectFailure(fn () => $this->deleteAlias()->handle(DomainFixture::superuser(), 'info@example.test', false, null));
        self::assertSame(AddressErrorCode::NEEDED_BY_CATCHALL, $failure->errorCode);
        self::assertSame(409, $failure->status);

        $failure = $this->expectFailure(fn () => $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'info@example.test',
            new AliasChange(enabled: false),
            null,
        ));
        self::assertSame(AddressErrorCode::NEEDED_BY_CATCHALL, $failure->errorCode);
        self::assertSame(['info@example.test|anna.example.test|anna.example.test|1'], $this->rowsOf('info@example.test'));
    }

    public function testADisabledCatchallDependsOnNothing(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('@example.test', 'info@example.test', '', 0);

        $this->deleteAlias()->handle(DomainFixture::superuser(), 'info@example.test', false, null);

        self::assertSame([], $this->rowsOf('info@example.test'));
    }

    public function testDisablingPostmasterNeedsConfirmation(): void
    {
        $this->virtual->givenRow('postmaster@example.test', 'anna.example.test', 'anna.example.test');

        $failure = $this->expectFailure(fn () => $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'postmaster@example.test',
            new AliasChange(enabled: false),
            null,
        ));
        self::assertSame(AddressErrorCode::RESERVED, $failure->errorCode);

        $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'postmaster@example.test',
            new AliasChange(enabled: false, confirmReserved: true),
            null,
        );
        self::assertSame(['postmaster@example.test|anna.example.test|anna.example.test|0'], $this->rowsOf('postmaster@example.test'));
    }

    public function testAnUpdateWithoutItsAuditEntryRestoresTheOldRows(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('info@example.test', 'ben.example.test', 'anna.example.test');
        $before = $this->allRows();
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->updateAlias()->handle(
                DomainFixture::superuser(),
                'info@example.test',
                new AliasChange(destinations: ['extern@elsewhere.test']),
                null,
            );
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertSame($before, $this->allRows());
        self::assertTrue($this->repairs->isEmpty());
    }

    public function testARowRemovedMeanwhileIsRefusedBeforeAnythingIsWritten(): void
    {
        $row = $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->domains->beforeNamespaceLockedWork(fn () => $this->virtual->remove($row));

        // The lookup inside the lock no longer finds the alias at all.
        $failure = $this->expectFailure(fn () => $this->updateAlias()->handle(
            DomainFixture::superuser(),
            'info@example.test',
            new AliasChange(enabled: false),
            null,
        ));

        self::assertSame(404, $failure->status);
        self::assertSame([], $this->rowsOf('info@example.test'));
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    public function testDeletingRemovesEveryRowOfTheAlias(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->virtual->givenRow('Info@example.test', 'ben.example.test', 'anna.example.test', 0);

        $this->deleteAlias()->handle(DomainFixture::superuser(), 'info@example.test', false, null);

        self::assertSame([], $this->rowsOf('info@example.test'));
        self::assertCount(1, $this->audit->withAction(DeleteAlias::OPERATION));
    }

    public function testDeletingAbuseNeedsConfirmation(): void
    {
        $this->virtual->givenRow('abuse@example.test', 'anna.example.test', 'anna.example.test');

        $failure = $this->expectFailure(fn () => $this->deleteAlias()->handle(
            DomainFixture::superuser(),
            'abuse@example.test',
            false,
            null,
        ));
        self::assertSame(AddressErrorCode::RESERVED, $failure->errorCode);

        $this->deleteAlias()->handle(DomainFixture::superuser(), 'abuse@example.test', true, null);
        self::assertSame([], $this->rowsOf('abuse@example.test'));
    }

    public function testADeletionWithoutItsAuditEntryPutsTheRowsBack(): void
    {
        $this->virtual->givenRow('info@example.test', 'anna.example.test', 'anna.example.test');
        $this->auditWriter = RefusingAuditWriter::everything($this->audit);

        try {
            $this->deleteAlias()->handle(DomainFixture::superuser(), 'info@example.test', false, null);
            self::fail('expected the audit failure');
        } catch (PersistenceError) {
        }

        self::assertSame(['info@example.test|anna.example.test|anna.example.test|1'], $this->rowsOf('info@example.test'));
    }

    public function testAMalformedPathIsNotFound(): void
    {
        $failure = $this->expectFailure(fn () => $this->deleteAlias()->handle(DomainFixture::superuser(), 'no-at-sign', false, null));

        self::assertSame(404, $failure->status);
    }


    // -----------------------------------------------------------------
    // Spellings that reach the list transport by another route
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function transportBypasses(): iterable
    {
        yield 'trailing dot' => ['team.other.test@maillist.invalid.'];
        yield 'trailing dots, upper case' => ['team.other.test@MAILLIST.INVALID..'];
        yield 'percent hack' => ['team.other.test%maillist.invalid@example.test'];
        yield 'bang path with domain' => ['maillist.invalid!team.other.test@example.test'];
        yield 'bang path without domain' => ['maillist.invalid!team.other.test'];
        yield 'source route' => ['@example.test:team.other.test@maillist.invalid'];
        yield 'source route to elsewhere' => ['@relay.test:someone@elsewhere.test'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transportBypasses')]
    public function testADestinationThatRoutesAroundTheTransportCheckIsRefused(string $destination): void
    {
        try {
            $this->createAlias()->handle(
                DomainFixture::domainMaster('anna', 'example.test'),
                self::domain(),
                new AliasDraft('sneak', [$destination]),
            );
            self::fail(sprintf('"%s" was accepted.', $destination));
        } catch (InvalidAddress $refused) {
            self::assertSame('address.invalid_destination', $refused->code());
        }

        self::assertSame([], $this->rowsOf('sneak@example.test'));
    }

    public function testExistingRowsWithSuchSpellingsAreStillReadAndChangedWithoutTouchingThem(): void
    {
        // Only new destinations are refused; a stored one is data (U-22).
        $this->virtual->givenRow('old@example.test', 'a%b@elsewhere.test,host!user@elsewhere.test', 'example.test');

        $state = $this->getAlias()->handle(DomainFixture::superuser(), 'old@example.test');
        self::assertSame(['a%b@elsewhere.test', 'host!user@elsewhere.test'], $state->destinations()->all());
        self::assertTrue($state->isEditable());

        $this->updateAlias()->handle(DomainFixture::superuser(), 'old@example.test', new AliasChange(enabled: false), null);

        self::assertSame(
            ['old@example.test|a%b@elsewhere.test,host!user@elsewhere.test|example.test|0'],
            $this->rowsOf('old@example.test'),
        );
    }
}

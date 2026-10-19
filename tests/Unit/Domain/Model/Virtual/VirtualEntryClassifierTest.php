<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model\Virtual;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\Alias;
use Mailadmin\Domain\Model\Virtual\CatchAll;
use Mailadmin\Domain\Model\Virtual\Forward;
use Mailadmin\Domain\Model\Virtual\MailboxAddress;
use Mailadmin\Domain\Model\Virtual\UnknownReason;
use Mailadmin\Domain\Model\Virtual\UnknownVirtualEntry;
use Mailadmin\Domain\Model\Virtual\VirtualEntryClassifier;
use Mailadmin\Domain\Model\Virtual\VirtualEntryType;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VirtualEntryClassifier::class)]
#[CoversClass(MailboxAddress::class)]
#[CoversClass(Alias::class)]
#[CoversClass(CatchAll::class)]
#[CoversClass(Forward::class)]
#[CoversClass(UnknownVirtualEntry::class)]
final class VirtualEntryClassifierTest extends TestCase
{
    private VirtualEntryClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new VirtualEntryClassifier();
    }

    public function testTheAccountsOwnAddressIsItsOwnType(): void
    {
        $row = new VirtualRow(
            'greta.mustermann@example.test',
            'greta.mustermann.example.test',
            'greta.mustermann.example.test',
        );

        $entry = $this->classifier->classify($row, DomainName::fromString('example.test'));

        self::assertInstanceOf(MailboxAddress::class, $entry);
        self::assertSame(VirtualEntryType::MailboxAddress, $entry->type());
        self::assertSame('greta.mustermann@example.test', $entry->address->toString());
        self::assertSame('greta.mustermann.example.test', $entry->cyrusUsername());
        self::assertTrue($entry->isEnabled());
    }

    public function testTheOwnAddressIsAlsoRecognisedInTheFqunScheme(): void
    {
        $row = new VirtualRow(
            'greta.mustermann@example.test',
            'greta.mustermann@example.test',
            'greta.mustermann@example.test',
        );

        $entry = $this->classifier->classify($row, DomainName::fromString('example.test'));

        self::assertInstanceOf(MailboxAddress::class, $entry);
    }

    public function testAnotherAddressOntoTheSameAccountIsAnAlias(): void
    {
        $row = new VirtualRow(
            'office@example.test',
            'greta.mustermann.example.test',
            'greta.mustermann.example.test',
        );

        $entry = $this->classifier->classify($row, DomainName::fromString('example.test'));

        self::assertInstanceOf(Alias::class, $entry);
        self::assertSame(VirtualEntryType::Alias, $entry->type());
        self::assertTrue($entry->targetsSingleAccount());
    }

    public function testALocalPartContainingTheDomainDoesNotTurnAnAddressIntoAnAlias(): void
    {
        // The REPLACE fragility of the allusers view, seen from the classifier:
        // a naive derivation of the address from the user name would produce
        // "info@example.test" and mistake the row for an alias.
        $row = new VirtualRow(
            'info.example.test@example.test',
            'info.example.test.example.test',
            'info.example.test.example.test',
        );

        $entry = $this->classifier->classify($row, DomainName::fromString('example.test'));

        self::assertInstanceOf(MailboxAddress::class, $entry);
        self::assertSame('info.example.test', $entry->identity->localPart->toString());
    }

    public function testWithoutTheDomainOfRecordAnAddressRowStaysAnAlias(): void
    {
        // Without accountuser.domain_name the two cannot be told apart. Alias
        // is the honest answer: it is an address that delivers to a Cyrus user.
        $row = new VirtualRow(
            'greta.mustermann@example.test',
            'greta.mustermann.example.test',
            'greta.mustermann.example.test',
        );

        self::assertInstanceOf(Alias::class, $this->classifier->classify($row));
    }

    public function testACatchAllIsRecognisedByTheLeadingAtSign(): void
    {
        $row = new VirtualRow('@example.test', 'info.example.test', 'info.example.test');

        $entry = $this->classifier->classify($row);

        self::assertInstanceOf(CatchAll::class, $entry);
        self::assertSame('example.test', $entry->domain->toString());
        self::assertSame(['info.example.test'], $entry->destinations()->all());
    }

    public function testAForwardIsRecognisedByTheEmptyUserName(): void
    {
        $row = new VirtualRow('greta.mustermann.example.test', 'elsewhere@other.test', '');

        $entry = $this->classifier->classify($row);

        self::assertInstanceOf(Forward::class, $entry);
        self::assertSame('greta.mustermann.example.test', $entry->source);
        self::assertSame(['elsewhere@other.test'], $entry->targets->all());
        self::assertFalse($entry->keepsLocalCopy());
    }

    public function testAForwardThatAlsoDeliversLocallyKeepsACopy(): void
    {
        $row = new VirtualRow(
            'greta.mustermann.example.test',
            'elsewhere@other.test, greta.mustermann.example.test',
            '',
        );

        $entry = $this->classifier->classify($row);

        self::assertInstanceOf(Forward::class, $entry);
        self::assertTrue($entry->keepsLocalCopy());
        self::assertSame(2, $entry->targets->count());
    }

    #[DataProvider('unknownRows')]
    public function testARowThatFitsNoPatternIsShownAsUnknown(
        VirtualRow $row,
        UnknownReason $expected,
    ): void {
        $entry = $this->classifier->classify($row, DomainName::fromString('example.test'));

        self::assertInstanceOf(UnknownVirtualEntry::class, $entry);
        self::assertSame(VirtualEntryType::Unknown, $entry->type());
        self::assertSame($expected, $entry->reason);
        // The row survives classification untouched, so it can be shown and
        // written back without loss (U-22).
        self::assertTrue($entry->row()->equals($row));
    }

    /**
     * @return iterable<string, array{VirtualRow, UnknownReason}>
     */
    public static function unknownRows(): iterable
    {
        yield 'no alias' => [
            new VirtualRow('', 'info.example.test', 'info.example.test'),
            UnknownReason::NoAlias,
        ];

        yield 'no destination' => [
            new VirtualRow('info@example.test', '', 'info.example.test'),
            UnknownReason::NoDestination,
        ];

        yield 'catchall without a domain' => [
            new VirtualRow('@', 'info.example.test', 'info.example.test'),
            UnknownReason::CatchAllWithoutDomain,
        ];

        yield 'alias is not an address' => [
            new VirtualRow('info', 'info.example.test', 'info.example.test'),
            UnknownReason::AliasIsNotAnAddress,
        ];

        yield 'catchall for an unusable domain' => [
            new VirtualRow('@exam ple.test', 'info.example.test', 'info.example.test'),
            UnknownReason::UnusableValue,
        ];
    }

    public function testADisabledRowIsClassifiedAndFlagged(): void
    {
        // status = 0 means Postfix skips the row. It is still a catchall, and
        // the interface has to be able to say so.
        $row = new VirtualRow('@example.test', 'info.example.test', 'info.example.test', 0);

        $entry = $this->classifier->classify($row);

        self::assertInstanceOf(CatchAll::class, $entry);
        self::assertFalse($entry->isEnabled());
    }

    public function testAnAliasOntoAnAccountWhoseIdentityContradictsItselfStaysAnAlias(): void
    {
        // The user name says one domain, accountuser.domain_name says another.
        // The row is still an address delivering to a Cyrus user; A6 reports
        // the broken account.
        $row = new VirtualRow(
            'office@example.test',
            'someone.other.test',
            'someone.other.test',
        );

        $entry = $this->classifier->classify($row, DomainName::fromString('example.test'));

        self::assertInstanceOf(Alias::class, $entry);
    }

    public function testABatchIsClassifiedWithTheDomainsTheCallerLookedUp(): void
    {
        $rows = [
            new VirtualRow(
                'greta.mustermann@example.test',
                'greta.mustermann.example.test',
                'greta.mustermann.example.test',
            ),
            new VirtualRow('@example.test', 'info.example.test', 'info.example.test'),
            new VirtualRow('broken', '', ''),
        ];

        $entries = $this->classifier->classifyAll($rows, [
            // The lookup ignores case, like every other name comparison here.
            'Greta.Mustermann.Example.Test' => DomainName::fromString('example.test'),
        ]);

        self::assertCount(3, $entries);
        self::assertSame(VirtualEntryType::MailboxAddress, $entries[0]->type());
        self::assertSame(VirtualEntryType::CatchAll, $entries[1]->type());
        self::assertSame(VirtualEntryType::Unknown, $entries[2]->type());
    }
}

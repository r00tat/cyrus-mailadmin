<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Address;

use Mailadmin\Application\Address\AccountTarget;
use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\AddressReader;
use Mailadmin\Application\Address\AliasLookup;
use Mailadmin\Application\Address\CatchallLookup;
use Mailadmin\Application\Address\CreateAlias;
use Mailadmin\Application\Address\DeleteAlias;
use Mailadmin\Application\Address\DeleteCatchall;
use Mailadmin\Application\Address\DeleteDomainAddress;
use Mailadmin\Application\Address\DeleteForwards;
use Mailadmin\Application\Address\DeleteVacation;
use Mailadmin\Application\Address\DestinationRules;
use Mailadmin\Application\Address\GetAlias;
use Mailadmin\Application\Address\GetCatchall;
use Mailadmin\Application\Address\GetForwards;
use Mailadmin\Application\Address\GetVacation;
use Mailadmin\Application\Address\ListDomainAddresses;
use Mailadmin\Application\Address\LoopGuard;
use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\Address\SenderRules;
use Mailadmin\Application\Address\SetCatchall;
use Mailadmin\Application\Address\SetForwards;
use Mailadmin\Application\Address\SetVacation;
use Mailadmin\Application\Address\UpdateAlias;
use Mailadmin\Application\Address\VacationScripts;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Application\MailingList\ListDeliveryMode;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Model\Virtual\VirtualEntryClassifier;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Infrastructure\Mailstore\InMemorySieveStore;
use Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller;
use Mailadmin\Infrastructure\Persistence\InMemoryAccountStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryMailingListRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryVirtualStore;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Tests\Support\DomainFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The wiring every A3 use-case test needs: an in-memory `virtual`, the
 * `accountuser` and `domain` tables, a Sieve store, a repair queue and an audit
 * trail that can be read back or told to refuse.
 *
 * Accounts are given to both the account store (which `AccountTarget` reads)
 * and the virtual store (which answers "is this an account name" the way the
 * join against `accountuser` does in SQL).
 */
abstract class AddressUseCaseTestCase extends TestCase
{
    protected InMemoryVirtualStore $virtual;
    protected InMemoryAccountStore $accounts;
    protected InMemoryDomainStore $domains;
    protected InMemorySieveStore $sieve;
    protected InMemoryRepairQueue $repairs;
    protected InMemoryAuditTrail $audit;
    protected InMemoryMailingListRepository $mailingLists;

    /** What the use cases write to; the trail itself unless a test swaps it. */
    protected AuditWriter $auditWriter;

    protected function setUp(): void
    {
        $this->virtual = new InMemoryVirtualStore();
        $this->accounts = new InMemoryAccountStore();
        $this->domains = new InMemoryDomainStore();
        $this->sieve = new InMemorySieveStore();
        $this->repairs = new InMemoryRepairQueue();
        $this->audit = new InMemoryAuditTrail();
        $this->mailingLists = new InMemoryMailingListRepository();
        $this->auditWriter = $this->audit;

        $this->domains->given(DomainFixture::domain('example.test'));
        $this->domains->given(DomainFixture::domain('other.test', prefix: 'other.test'));
    }

    // -----------------------------------------------------------------
    // Arrangement
    // -----------------------------------------------------------------

    /**
     * An account with the address row every account has (architecture 3.4).
     */
    protected function givenAccount(string $storedUsername, string $domain = 'example.test', bool $withAddressRow = true): void
    {
        $this->accounts->given($storedUsername, $domain);
        $this->virtual->givenAccount($storedUsername, $domain);

        if ($withAddressRow) {
            $identity = $this->identity($storedUsername, $domain);
            $this->virtual->givenRow($identity->address()->toString(), $storedUsername, $storedUsername);
        }
    }

    protected function identity(string $storedUsername, string $domain = 'example.test'): MailboxIdentity
    {
        return MailboxIdentity::fromStoredUsername($storedUsername, DomainName::fromStoredValue($domain));
    }

    protected static function domain(string $name = 'example.test'): DomainName
    {
        return DomainName::fromStoredValue($name);
    }

    /**
     * @return list<string> `alias|dest|username|status` for every row with this alias
     */
    protected function rowsOf(string $alias): array
    {
        return array_map(
            static fn (VirtualRow $row): string => sprintf('%s|%s|%s|%d', $row->alias, $row->dest, $row->username, $row->status),
            $this->virtual->rowsWithAlias($alias),
        );
    }

    /**
     * @return list<string>
     */
    protected function allRows(): array
    {
        $rows = array_map(
            static fn (VirtualRow $row): string => sprintf('%s|%s|%s|%d', $row->alias, $row->dest, $row->username, $row->status),
            $this->virtual->all(),
        );
        sort($rows);

        return $rows;
    }

    protected function expectFailure(callable $call): AddressOperationFailed
    {
        try {
            $call();
        } catch (AddressOperationFailed $failure) {
            return $failure;
        }

        self::fail('The operation was expected to be refused.');
    }

    // -----------------------------------------------------------------
    // Use cases
    // -----------------------------------------------------------------

    protected function reader(): AddressReader
    {
        return new AddressReader(
            $this->virtual,
            new VirtualEntryClassifier(),
            $this->mailingLists,
            new ListDelivery(DomainName::fromString('maillist.invalid'), ListDeliveryMode::Transport),
        );
    }

    protected function writer(): RowWriter
    {
        return new RowWriter($this->virtual, $this->auditWriter, $this->repairs, new NullLogger());
    }

    protected function destinations(): DestinationRules
    {
        return new DestinationRules($this->virtual, \Mailadmin\Tests\Support\ListDeliveryFixture::delivery());
    }

    protected function loops(): LoopGuard
    {
        return new LoopGuard($this->virtual);
    }

    protected function senders(): SenderRules
    {
        return new SenderRules($this->virtual);
    }

    protected function target(): AccountTarget
    {
        return new AccountTarget($this->accounts);
    }

    protected function scripts(): VacationScripts
    {
        return new VacationScripts(
            $this->sieve,
            new SieveScriptInstaller($this->sieve, new SieveRenderer()),
            $this->auditWriter,
            $this->repairs,
            new NullLogger(),
        );
    }

    protected function listAddresses(): ListDomainAddresses
    {
        return new ListDomainAddresses($this->reader(), $this->domains);
    }

    protected function createAlias(): CreateAlias
    {
        return new CreateAlias(
            $this->virtual,
            $this->reader(),
            $this->domains,
            $this->destinations(),
            $this->senders(),
            $this->writer(),
            $this->loops(),
        );
    }

    protected function getAlias(): GetAlias
    {
        return new GetAlias(new AliasLookup($this->reader()));
    }

    protected function updateAlias(): UpdateAlias
    {
        return new UpdateAlias(
            new AliasLookup($this->reader()),
            $this->reader(),
            $this->domains,
            $this->destinations(),
            $this->senders(),
            $this->writer(),
            $this->loops(),
        );
    }

    protected function deleteAlias(): DeleteAlias
    {
        return new DeleteAlias(new AliasLookup($this->reader()), $this->domains, $this->writer(), $this->loops());
    }

    protected function deleteEntry(): DeleteDomainAddress
    {
        return new DeleteDomainAddress($this->reader(), $this->domains, $this->writer(), $this->loops());
    }

    protected function catchallLookup(): CatchallLookup
    {
        return new CatchallLookup($this->reader(), $this->domains);
    }

    protected function getCatchall(): GetCatchall
    {
        return new GetCatchall($this->catchallLookup());
    }

    protected function setCatchall(): SetCatchall
    {
        return new SetCatchall(
            $this->reader(),
            $this->catchallLookup(),
            $this->domains,
            $this->destinations(),
            $this->senders(),
            $this->writer(),
            $this->loops(),
        );
    }

    protected function deleteCatchall(): DeleteCatchall
    {
        return new DeleteCatchall($this->catchallLookup(), $this->domains, $this->writer());
    }

    protected function getForwards(): GetForwards
    {
        return new GetForwards($this->target(), $this->reader());
    }

    protected function setForwards(): SetForwards
    {
        return new SetForwards($this->target(), $this->reader(), $this->domains, $this->destinations(), $this->writer(), $this->loops());
    }

    protected function deleteForwards(): DeleteForwards
    {
        return new DeleteForwards($this->target(), $this->reader(), $this->domains, $this->writer());
    }

    protected function getVacation(): GetVacation
    {
        return new GetVacation($this->target(), $this->scripts());
    }

    protected function setVacation(): SetVacation
    {
        return new SetVacation($this->target(), $this->scripts());
    }

    protected function deleteVacation(): DeleteVacation
    {
        return new DeleteVacation($this->target(), $this->scripts());
    }

    protected static function fqun(): NamingScheme
    {
        return NamingScheme::Fqun;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Domain;

use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\CreateDomain;
use Mailadmin\Application\Domain\DeleteDomain;
use Mailadmin\Application\Domain\DomainDefaults;
use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Application\Domain\DomainView;
use Mailadmin\Application\Domain\GetDomain;
use Mailadmin\Application\Domain\ListDomains;
use Mailadmin\Application\Domain\RenameDomain;
use Mailadmin\Application\Domain\UpdateDomain;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryMailingListRepository;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Tests\Support\ListDeliveryFixture;
use Mailadmin\Tests\Support\Mailstore\UnreliableMailstore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The wiring every A1 use-case test needs: an in-memory `domain` table, a mail
 * store that can be told to fail, and a repair queue that can be read back.
 *
 * The audit trail is S4's in-memory double, which runs the same `Redactor` as
 * the real writer — so a test here can show both that an entry was written and
 * that nothing secret ended up in it.
 *
 * Nothing here touches MariaDB or Cyrus. That is the point of the two ports —
 * the interesting half of this package is what happens when the second of two
 * systems refuses, and one cannot ask a real server for that on demand.
 */
abstract class DomainUseCaseTestCase extends TestCase
{
    protected InMemoryDomainStore $store;
    protected UnreliableMailstore $mailstore;
    protected InMemoryRepairQueue $repairs;
    protected DomainDefaults $defaults;
    protected InMemoryAuditTrail $audit;
    protected InMemoryMailingListRepository $lists;

    /** What the use cases write to; the trail itself unless a test swaps it. */
    protected AuditWriter $auditWriter;

    protected function setUp(): void
    {
        $this->store = new InMemoryDomainStore();
        $this->mailstore = new UnreliableMailstore(new InMemoryMailstore());
        $this->repairs = new InMemoryRepairQueue();
        $this->defaults = new DomainDefaults();
        $this->audit = new InMemoryAuditTrail();
        $this->auditWriter = $this->audit;
        $this->lists = new InMemoryMailingListRepository();
    }

    protected function listDomains(): ListDomains
    {
        return new ListDomains($this->store);
    }

    protected function getDomain(): GetDomain
    {
        return new GetDomain($this->store);
    }

    protected function createDomain(): CreateDomain
    {
        return new CreateDomain($this->store, $this->defaults, $this->auditWriter, ListDeliveryFixture::delivery());
    }

    protected function renameDomain(): RenameDomain
    {
        return new RenameDomain(
            $this->store,
            $this->mailstore,
            $this->repairs,
            $this->defaults,
            $this->auditWriter,
            new NullLogger(),
            ListDeliveryFixture::delivery(),
            $this->lists,
        );
    }

    protected function updateDomain(): UpdateDomain
    {
        return new UpdateDomain($this->store, $this->renameDomain(), $this->defaults, $this->auditWriter);
    }

    protected function deleteDomain(): DeleteDomain
    {
        return new DeleteDomain($this->store, $this->defaults, $this->auditWriter, new NullLogger());
    }

    /**
     * The `If-Match` value a client that had just read this domain would send.
     */
    protected function etagOf(DomainSummary $summary): string
    {
        return DomainView::etag(DomainView::full($summary, $this->defaults));
    }

    protected function currentEtag(string $name): string
    {
        $summary = $this->store->find(DomainName::fromStoredValue($name));

        self::assertNotNull($summary);

        return $this->etagOf($summary);
    }

    /**
     * Give the mail store the INBOX of an account, so that a rename has
     * something to move.
     */
    protected function giveMailbox(string $storedUsername, string $domain): MailboxIdentity
    {
        $identity = MailboxIdentity::fromStoredUsername(
            $storedUsername,
            DomainName::fromStoredValue($domain),
        );

        $this->mailstore->inner()->createMailbox($identity);

        return $identity;
    }
}

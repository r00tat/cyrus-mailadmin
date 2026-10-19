<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Audit;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Audit\AuditRecord;
use Mailadmin\Application\Audit\ListAuditTrail;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\Page;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading the trail back — filtering, paging and, above all, scoping.
 *
 * The scoping cases are the reason this file is not just a repository test. They
 * describe what a domain master would see **if** `audit.read` were ever widened
 * to that role (U-43 keeps it with the superuser today), and they are here now
 * rather than then because "we will scope it when we grant it" is how an
 * unscoped listing ships.
 */
#[CoversClass(ListAuditTrail::class)]
#[CoversClass(InMemoryAuditTrail::class)]
#[CoversClass(AuditRecord::class)]
final class ListAuditTrailTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryAuditTrail $trail;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->trail = new InMemoryAuditTrail(clock: $this->clock);
    }

    public function testEverythingRecordedComesBack(): void
    {
        $this->record('domain.create', 'example.test');
        $this->record('domain.delete', 'other.test');

        $page = $this->list(new ListCriteria());

        self::assertSame(2, $page->total);
        self::assertCount(2, $page->items);
    }

    public function testTheNewestEntryComesFirstWhenNothingSaysOtherwise(): void
    {
        $this->record('domain.create', 'first.test');
        $this->clock->advance(60);
        $this->record('domain.create', 'second.test');

        $page = $this->list(new ListCriteria(order: ListAuditTrail::defaultOrder()));

        self::assertSame('second.test', $page->items[0]->objectId);
    }

    public function testTheOrderCanBeTurnedRound(): void
    {
        $this->record('domain.create', 'first.test');
        $this->clock->advance(60);
        $this->record('domain.create', 'second.test');

        $page = $this->list(new ListCriteria(order: SortDirection::Ascending));

        self::assertSame('first.test', $page->items[0]->objectId);
    }

    /**
     * Two entries in the same second is the ordinary case for a batch, not an
     * edge case. Without a tiebreaker their relative order is the server's to
     * choose, and a row can then appear on page one and again on page two.
     */
    public function testEntriesRecordedInTheSameSecondStillHaveAStableOrder(): void
    {
        $this->record('account.rename', 'a@example.test');
        $this->record('account.rename', 'b@example.test');
        $this->record('account.rename', 'c@example.test');

        $ascending = $this->list(new ListCriteria(perPage: 2, order: SortDirection::Ascending));
        $second = $this->list(new ListCriteria(page: 2, perPage: 2, order: SortDirection::Ascending));

        self::assertSame(['a@example.test', 'b@example.test'], self::identifiers($ascending->items));
        self::assertSame(['c@example.test'], self::identifiers($second->items));
    }

    public function testTheActionFilterMatchesAWholeFamilyByPrefix(): void
    {
        $this->record('domain.create', 'example.test');
        $this->record('domain.delete', 'other.test');
        $this->record('account.create', 'anna@example.test');

        self::assertSame(2, $this->list(new ListCriteria(filters: ['action' => 'domain.']))->total);
        self::assertSame(1, $this->list(new ListCriteria(filters: ['action' => 'domain.create']))->total);
    }

    public function testTheOutcomeFilterFindsTheRefusals(): void
    {
        $this->record('domain.delete', 'example.test');
        $this->trail->record(AuditEntry::refusal(
            $this->superuser(),
            'domain.delete',
            'domain',
            'forbidden.test',
            reason: 'the role does not hold domain.delete',
        ));

        $page = $this->list(new ListCriteria(filters: ['outcome' => AuditOutcome::Denied->value]));

        self::assertSame(1, $page->total);
        self::assertSame('forbidden.test', $page->items[0]->objectId);
    }

    public function testThePartsOfOneOperationCanBeAskedForTogether(): void
    {
        $operation = $this->trail->record(AuditEntry::change(
            $this->superuser(),
            'domain.rename',
            'domain',
            'example.test',
        ));

        foreach (['a', 'b'] as $local) {
            $this->trail->record(
                AuditEntry::change($this->superuser(), 'account.rename', 'account', $local . '@example.test')
                    ->partOf($operation),
            );
        }

        $page = $this->list(new ListCriteria(filters: ['part_of' => (string) $operation->value]));

        self::assertSame(2, $page->total);
    }

    public function testAPageIsAPageAndTheTotalCountsEverythingMatching(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->record('domain.create', 'domain-' . $i . '.test');
        }

        $page = $this->list(new ListCriteria(page: 2, perPage: 3));

        self::assertCount(3, $page->items);
        self::assertSame(7, $page->total);
        self::assertSame(3, $page->pageCount());
    }

    public function testASuperuserSeesEntriesThatBelongToNoDomain(): void
    {
        $this->trail->record(AuditEntry::creation($this->superuser(), 'session.sign_in', 'session', 'root'));

        self::assertSame(1, $this->list(new ListCriteria())->total);
    }

    /**
     * The narrowing that does nothing today and everything if `audit.read` is
     * ever widened.
     */
    public function testADomainMasterWouldSeeOnlyTheirOwnDomainsEntries(): void
    {
        $this->record('domain.update', 'own.example', domain: 'own.example');
        $this->record('domain.update', 'other.example', domain: 'other.example');

        $page = $this->trail->list($this->domainMaster('own.example'), new ListCriteria());

        self::assertSame(1, $page->total);
        self::assertSame('own.example', $page->items[0]->domain);
    }

    /**
     * An entry that belongs to no domain belongs to no domain master's scope
     * either — the same answer `Repository::scopedTo()` gives in SQL, where a
     * `NULL` never satisfies an `IN`.
     */
    public function testADomainMasterWouldNotSeeAnEntryWithoutADomain(): void
    {
        $this->trail->record(AuditEntry::creation($this->superuser(), 'session.sign_in', 'session', 'root'));

        self::assertSame(0, $this->trail->list($this->domainMaster('own.example'), new ListCriteria())->total);
    }

    /**
     * S4's definition of done, at the level where the A-packages will meet it:
     * whatever a use case hands over, the trail does not hold the secret.
     */
    public function testASecretHandedToTheWriterNeverAppearsInWhatComesBack(): void
    {
        $this->trail->record(AuditEntry::change(
            $this->superuser(),
            'account.password.write',
            'account',
            'anna@example.test',
            after: ['password' => 'correct-horse-battery-staple', 'password_hash' => '$2y$10$aaaa'],
        ));

        $document = (string) json_encode($this->list(new ListCriteria())->items[0]->toRepresentation());

        self::assertStringNotContainsString('correct-horse-battery-staple', $document);
        self::assertStringNotContainsString('$2y$10$', $document);
    }

    // -----------------------------------------------------------------

    /**
     * @return Page<AuditRecord>
     */
    private function list(ListCriteria $criteria): Page
    {
        return (new ListAuditTrail($this->trail))->handle($this->superuser(), $criteria);
    }

    private function record(string $action, string $objectId, ?string $domain = null): void
    {
        $this->trail->record(AuditEntry::creation(
            $this->superuser(),
            $action,
            explode('.', $action)[0],
            $objectId,
            domain: $domain,
        ));
    }

    /**
     * @param list<AuditRecord> $records
     *
     * @return list<string>
     */
    private static function identifiers(array $records): array
    {
        return array_map(static fn (AuditRecord $record): string => $record->objectId, $records);
    }

    private function superuser(): ActorContext
    {
        return ActorContext::superuser('root');
    }

    private function domainMaster(string $domain): ActorContext
    {
        return ActorContext::domainMaster('master', DomainName::fromString($domain));
    }
}

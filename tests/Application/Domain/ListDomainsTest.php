<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Domain;

use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Application\Domain\GetDomain;
use Mailadmin\Application\Domain\ListDomains;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Tests\Support\DomainFixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ListDomains::class)]
#[CoversClass(GetDomain::class)]
final class ListDomainsTest extends DomainUseCaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->store->given(DomainFixture::domain('alpha.test', maxAccounts: 30));
        $this->store->given(DomainFixture::domain('beta.test', maxAccounts: 10), 'anna.beta.test');
        $this->store->given(DomainFixture::domain('gamma.test', maxAccounts: 20));
    }

    public function testASuperuserSeesEverything(): void
    {
        $page = $this->listDomains()->handle(DomainFixture::superuser(), new ListCriteria());

        self::assertSame(3, $page->total);
        self::assertSame(['alpha.test', 'beta.test', 'gamma.test'], $this->names($page->items));
    }

    public function testADomainMasterSeesOnlyTheirOwnAndTheTotalSaysSo(): void
    {
        // The narrowing is a predicate in the query, not a filter afterwards
        // (U-45). If it were applied after paging, `meta.total` would count rows
        // the caller may not see and the page indicator would lie.
        $page = $this->listDomains()->handle(
            DomainFixture::domainMaster('anna', 'beta.test'),
            new ListCriteria(),
        );

        self::assertSame(1, $page->total);
        self::assertSame(['beta.test'], $this->names($page->items));
    }

    public function testSortingIsLimitedToWhatTheResourceOffers(): void
    {
        $page = $this->listDomains()->handle(
            DomainFixture::superuser(),
            new ListCriteria(sort: 'max_accounts', order: SortDirection::Descending),
        );

        self::assertSame(['alpha.test', 'gamma.test', 'beta.test'], $this->names($page->items));
    }

    public function testAFieldTheResourceDoesNotOfferIsRefusedRatherThanIgnored(): void
    {
        // Rule two of CLAUDE.md. The legacy system interpolated the sort field
        // out of the session straight into the SQL.
        $this->expectException(CriteriaError::class);

        $this->listDomains()->handle(
            DomainFixture::superuser(),
            new ListCriteria(sort: 'password'),
        );
    }

    public function testAnUndeclaredFilterIsRefusedToo(): void
    {
        $this->expectException(CriteriaError::class);

        $this->listDomains()->handle(
            DomainFixture::superuser(),
            new ListCriteria(filters: ['prefix' => 'alpha']),
        );
    }

    public function testTheNameFilterMatchesASubstringWithoutRegardToCase(): void
    {
        $page = $this->listDomains()->handle(
            DomainFixture::superuser(),
            new ListCriteria(filters: ['name' => 'ETA']),
        );

        self::assertSame(['beta.test'], $this->names($page->items));
    }

    public function testPagingCarriesTheMetaBlockTheContractPromises(): void
    {
        $page = $this->listDomains()->handle(
            DomainFixture::superuser(),
            new ListCriteria(page: 2, perPage: 2),
        );

        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 3], $page->meta());
        self::assertSame(['gamma.test'], $this->names($page->items));
    }

    public function testADomainMasterMayReadTheirOwnDomain(): void
    {
        $summary = $this->getDomain()->handle(
            DomainFixture::domainMaster('anna', 'beta.test'),
            DomainFixture::name('beta.test'),
        );

        self::assertSame('beta.test', $summary->domain->name->toString());
        self::assertSame(1, $summary->accountCount);
    }

    public function testAForeignDomainIsHiddenBeforeItIsEvenLookedUp(): void
    {
        // ResourceNotVisible, which the authorisation middleware renders as the
        // same `404` a missing domain gets (U-44). Note that the row is never
        // read — the policy runs first.
        $this->expectException(ResourceNotVisible::class);

        $this->getDomain()->handle(
            DomainFixture::domainMaster('anna', 'beta.test'),
            DomainFixture::name('alpha.test'),
        );
    }

    /**
     * @param list<DomainSummary> $items
     *
     * @return list<string>
     */
    private function names(array $items): array
    {
        return array_map(
            static fn (DomainSummary $summary): string => $summary->domain->name->toString(),
            $items,
        );
    }
}

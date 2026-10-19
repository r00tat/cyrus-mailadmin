<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\DomainScope;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\OpenConnection;
use Mailadmin\Infrastructure\Persistence\Repository;
use Mailadmin\Tests\Unit\Infrastructure\Persistence\Fixtures\WidgetRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The repository base, driven through a repository written the way A1–A6 will
 * write theirs.
 *
 * SQLite in memory: paging, ordering, filtering and transactions are portable
 * SQL, and the value of testing them here is that every A package inherits the
 * result. What is not portable — `GET_LOCK`, `INSERT IGNORE`, the session time
 * zone — is in the integration suite and skips without a database.
 */
#[CoversClass(Repository::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class RepositoryTest extends TestCase
{
    private WidgetRepository $repository;

    private Connection $connection;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec(WidgetRepository::SCHEMA);

        $this->connection = new Connection(new OpenConnection($pdo));
        $this->repository = new WidgetRepository($this->connection);

        $this->repository->insert(1, 'alpha', 'one.example.test', 10);
        $this->repository->insert(2, 'bravo', 'one.example.test', 30);
        $this->repository->insert(3, 'charlie', 'two.example.test', 20);
        $this->repository->insert(4, 'delta', 'two.example.test', 20);
        $this->repository->insert(5, 'echo', 'three.example.test', 50);
    }

    public function testAPageCarriesTheItemsAndTheTotal(): void
    {
        $page = $this->repository->page(new ListCriteria(page: 1, perPage: 2));

        self::assertCount(2, $page->items);
        self::assertSame(5, $page->total);
        self::assertSame(['page' => 1, 'per_page' => 2, 'total' => 5], $page->meta());
        self::assertSame(3, $page->pageCount());
    }

    public function testThePagesTogetherAreTheWholeResultWithNoRepeats(): void
    {
        $seen = [];

        foreach ([1, 2, 3] as $number) {
            foreach ($this->repository->page(new ListCriteria(page: $number, perPage: 2))->items as $item) {
                $seen[] = $item['id'];
            }
        }

        self::assertSame([1, 2, 3, 4, 5], $seen);
    }

    /**
     * `size` has duplicates. Without the tiebreaker the server could return the
     * two size-20 rows in either order on either page, and a row would go
     * missing while another appeared twice.
     */
    public function testPagingIsStableWhenTheSortFieldHasDuplicates(): void
    {
        $first = $this->repository->page(new ListCriteria(page: 1, perPage: 2, sort: 'size'));
        $second = $this->repository->page(new ListCriteria(page: 2, perPage: 2, sort: 'size'));

        self::assertSame([1, 3], array_column($first->items, 'id'));
        self::assertSame([4, 2], array_column($second->items, 'id'));
    }

    public function testTheDefaultSortAppliesWhenTheRequestNamesNone(): void
    {
        $page = $this->repository->page(new ListCriteria(perPage: 5));

        self::assertSame(
            ['alpha', 'bravo', 'charlie', 'delta', 'echo'],
            array_column($page->items, 'name'),
        );
    }

    public function testTheDirectionIsHonoured(): void
    {
        $page = $this->repository->page(
            new ListCriteria(perPage: 5, sort: 'name', order: SortDirection::Descending),
        );

        self::assertSame('echo', $page->items[0]['name']);
    }

    /**
     * The failure the whole package is built around: an ordering that came from
     * the request and was not declared never reaches the database.
     */
    public function testAnUndeclaredSortFieldStopsTheQuery(): void
    {
        $this->expectException(CriteriaError::class);

        $this->repository->page(new ListCriteria(sort: 'domain'));
    }

    public function testAnInjectionInTheSortParameterStopsTheQuery(): void
    {
        $this->expectException(CriteriaError::class);

        $this->repository->page(new ListCriteria(sort: 'name; DROP TABLE widget'));
    }

    public function testFiltersNarrowBothTheRowsAndTheTotal(): void
    {
        $page = $this->repository->page(
            new ListCriteria(perPage: 50, filters: ['domain' => 'two.example.test']),
        );

        self::assertSame(2, $page->total);
        self::assertSame(['charlie', 'delta'], array_column($page->items, 'name'));
    }

    public function testSeveralFiltersCombine(): void
    {
        $page = $this->repository->page(new ListCriteria(
            perPage: 50,
            filters: ['domain' => 'two.example.test', 'min_size' => 20],
        ));

        self::assertSame(2, $page->total);
    }

    public function testAContainsFilterDoesNotTreatAnUnderscoreAsAWildcard(): void
    {
        $this->repository->insert(6, 'a_b', 'one.example.test', 1);
        $this->repository->insert(7, 'axb', 'one.example.test', 1);

        $page = $this->repository->page(new ListCriteria(perPage: 50, filters: ['name' => 'a_b']));

        self::assertSame(1, $page->total);
        self::assertSame('a_b', $page->items[0]['name']);
    }

    public function testAnUndeclaredFilterStopsTheQuery(): void
    {
        $this->expectException(CriteriaError::class);

        $this->repository->page(new ListCriteria(filters: ['size' => 10]));
    }

    public function testADomainScopeNarrowsTheListing(): void
    {
        $page = $this->repository->pageWithinDomains(
            new ListCriteria(perPage: 50),
            ['one.example.test', 'three.example.test'],
        );

        self::assertSame(3, $page->total);
        self::assertSame(['alpha', 'bravo', 'echo'], array_column($page->items, 'name'));
    }

    /**
     * An administrator with no domains sees nothing. "No predicate" would show
     * them everything, which is the wrong way round to be wrong.
     */
    public function testAnEmptyDomainScopeShowsNothing(): void
    {
        $page = $this->repository->pageWithinDomains(new ListCriteria(), []);

        self::assertTrue($page->isEmpty());
        self::assertSame(0, $page->total);
    }

    public function testReadingTheScopeOffTheActorNarrowsTheSameWay(): void
    {
        $page = $this->repository->pageForActor(
            new ListCriteria(perPage: 50),
            ActorContext::of(
                'master',
                AdminRole::DomainMaster,
                DomainScope::of(
                    DomainName::fromString('one.example.test'),
                    DomainName::fromString('three.example.test'),
                ),
            ),
        );

        self::assertSame(3, $page->total);
        self::assertSame(['alpha', 'bravo', 'echo'], array_column($page->items, 'name'));
    }

    public function testASuperuserSeesEverythingWithoutTheCallerWritingAnIf(): void
    {
        // The point of `scopedTo` (S3). `restrictToDomains` needs an
        // `if ($actor->requiresDomainScope())` around it, and that `if` is what
        // a caller forgets — silently widening every listing instead of failing.
        $page = $this->repository->pageForActor(
            new ListCriteria(perPage: 50),
            ActorContext::superuser('root'),
        );

        self::assertSame($this->repository->page(new ListCriteria(perPage: 50))->total, $page->total);
    }

    public function testARepositoryBindingThatWouldBeOverwrittenIsRefused(): void
    {
        $this->expectException(CriteriaError::class);
        $this->expectExceptionMessageMatches('/overwritten silently/');

        $this->repository->pageWithACollidingBinding(new ListCriteria());
    }

    public function testAnEmptyResultCostsNoSecondQuery(): void
    {
        $page = $this->repository->page(
            new ListCriteria(filters: ['domain' => 'nowhere.example.test']),
        );

        self::assertSame(0, $page->total);
        self::assertSame([], $page->items);
    }

    public function testFetchAllReturnsEverythingInTheDeclaredOrder(): void
    {
        self::assertSame(
            ['alpha', 'bravo', 'charlie', 'delta', 'echo'],
            array_column($this->repository->all(), 'name'),
        );
    }

    public function testFetchAllStillGoesThroughTheAllowlist(): void
    {
        $this->expectException(CriteriaError::class);

        $this->repository->all('domain');
    }

    public function testAGroupedListingCountsGroupsNotRows(): void
    {
        $page = $this->repository->pageGroupedByDomain(new ListCriteria(perPage: 50, sort: 'id'));

        self::assertSame(3, $page->total);
    }

    public function testTheTransactionHelperCommitsBothRows(): void
    {
        $this->repository->insertPair(10, 11, 'one.example.test');

        self::assertSame(7, (int) $this->connection->selectValue('SELECT COUNT(*) FROM widget'));
    }

    public function testAFailureInsideTheTransactionLeavesNothingBehind(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->connection->transactional(function (): void {
                $this->repository->insert(20, 'twenty', 'one.example.test', 1);

                throw new RuntimeException('the mailstore refused');
            });
        } finally {
            self::assertSame(5, (int) $this->connection->selectValue('SELECT COUNT(*) FROM widget'));
        }
    }
}

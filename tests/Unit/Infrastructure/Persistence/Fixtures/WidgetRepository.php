<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Fixtures;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use Mailadmin\Infrastructure\Persistence\ListQuery;
use Mailadmin\Infrastructure\Persistence\Page;
use Mailadmin\Infrastructure\Persistence\Repository;

/**
 * A repository over a synthetic table, written the way the A packages are meant
 * to write theirs.
 *
 * It exists so that the base class is tested through the interface its
 * consumers see rather than through its private methods. The table and the data
 * are invented; `example.test` is a reserved test domain and nothing here
 * resembles the production database.
 */
final class WidgetRepository extends Repository
{
    public const string SCHEMA = <<<'SQL'
        CREATE TABLE widget (
            id      INTEGER PRIMARY KEY,
            name    TEXT    NOT NULL,
            domain  TEXT    NOT NULL,
            size    INTEGER NOT NULL
        )
        SQL;

    protected function sortable(): SortAllowlist
    {
        return new SortAllowlist(
            ['name' => 'w.name', 'size' => 'w.size', 'id' => 'w.id'],
            default: 'name',
            tiebreaker: 'id',
        );
    }

    protected function filterable(): FilterAllowlist
    {
        return new FilterAllowlist(
            new FilterField('name', 'w.name', FilterComparison::Contains),
            new FilterField('domain', 'w.domain'),
            new FilterField('min_size', 'w.size', FilterComparison::GreaterOrEqual),
        );
    }

    /**
     * @return Page<array{id: int, name: string}>
     */
    public function page(ListCriteria $criteria): Page
    {
        return $this->fetchPage(
            new ListQuery(select: 'w.id, w.name', from: 'widget w'),
            $criteria,
            $this->toWidget(...),
        );
    }

    /**
     * @param list<string> $domains
     *
     * @return Page<array{id: int, name: string}>
     */
    public function pageWithinDomains(ListCriteria $criteria, array $domains): Page
    {
        $query = $this->restrictToDomains(
            new ListQuery(select: 'w.id, w.name', from: 'widget w'),
            $domains,
            'w.domain',
        );

        return $this->fetchPage($query, $criteria, $this->toWidget(...));
    }

    /**
     * The form S3 wants A1 to A6 to use: no `if` for a caller to forget.
     *
     * @return Page<array{id: int, name: string}>
     */
    public function pageForActor(ListCriteria $criteria, ActorContext $actor): Page
    {
        $query = $this->scopedTo(
            new ListQuery(select: 'w.id, w.name', from: 'widget w'),
            $actor,
            'w.domain',
        );

        return $this->fetchPage($query, $criteria, $this->toWidget(...));
    }

    /**
     * A repository doing it wrong: binding a name the base also generates.
     *
     * @return Page<array{id: int, name: string}>
     */
    public function pageWithACollidingBinding(ListCriteria $criteria): Page
    {
        return $this->fetchPage(
            new ListQuery(
                select: 'w.id, w.name',
                from: 'widget w',
                where: 'w.size >= :ma_limit',
                bindings: ['ma_limit' => 1],
            ),
            $criteria,
            $this->toWidget(...),
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function all(?string $sort = null): array
    {
        return $this->fetchAll(
            new ListQuery(select: 'w.id, w.name', from: 'widget w'),
            $this->toWidget(...),
            $sort,
        );
    }

    /**
     * @return Page<array{id: int, name: string}>
     */
    public function pageGroupedByDomain(ListCriteria $criteria): Page
    {
        return $this->fetchPage(
            new ListQuery(
                select: 'MIN(w.id) AS id, w.domain AS name',
                from: 'widget w',
                groupBy: 'w.domain',
            ),
            $criteria,
            $this->toWidget(...),
        );
    }

    public function insert(int $id, string $name, string $domain, int $size): void
    {
        $this->connection->execute(
            'INSERT INTO widget (id, name, domain, size) VALUES (:id, :name, :domain, :size)',
            ['id' => $id, 'name' => $name, 'domain' => $domain, 'size' => $size],
        );
    }

    /**
     * Two rows that have to arrive together — the shape of every write in
     * architecture 8.1, with the mailstore call kept outside by the caller.
     */
    public function insertPair(int $firstId, int $secondId, string $domain): void
    {
        $this->transactional(function () use ($firstId, $secondId, $domain): void {
            $this->insert($firstId, 'pair-' . $firstId, $domain, 1);
            $this->insert($secondId, 'pair-' . $secondId, $domain, 1);
        });
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, name: string}
     */
    private function toWidget(array $row): array
    {
        return [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
        ];
    }
}

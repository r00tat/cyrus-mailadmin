<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence;

use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\ListQuery;
use Mailadmin\Infrastructure\Persistence\Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Page::class)]
#[CoversClass(ListQuery::class)]
final class PageTest extends TestCase
{
    public function testTheMetaBlockHasExactlyTheShapeTheContractDeclares(): void
    {
        $page = new Page(['a', 'b'], page: 2, perPage: 25, total: 61);

        self::assertSame(['page' => 2, 'per_page' => 25, 'total' => 61], $page->meta());
    }

    public function testTotalCountsEverythingMatchingNotWhatIsOnThePage(): void
    {
        $page = new Page(['a'], page: 3, perPage: 1, total: 7);

        self::assertCount(1, $page->items);
        self::assertSame(7, $page->total);
        self::assertSame(7, $page->pageCount());
    }

    public function testAnEmptyResultHasNoPages(): void
    {
        $page = Page::empty(new ListCriteria());

        self::assertTrue($page->isEmpty());
        self::assertSame(0, $page->pageCount());
        self::assertSame(0, $page->total);
    }

    public function testMappingKeepsThePageInformation(): void
    {
        $page = new Page([1, 2, 3], page: 2, perPage: 3, total: 9);

        $mapped = $page->map(static fn (int $value): string => (string) $value);

        self::assertSame(['1', '2', '3'], $mapped->items);
        self::assertSame($page->meta(), $mapped->meta());
    }

    public function testAndWhereKeepsBothPredicates(): void
    {
        $query = (new ListQuery('a', 't', 'x = :x', ['x' => 1]))
            ->andWhere('y = :y', ['y' => 2]);

        self::assertSame('(x = :x) AND (y = :y)', $query->where);
        self::assertSame(['x' => 1, 'y' => 2], $query->bindings);
    }

    public function testAndWhereOnAQueryWithoutAPredicateDoesNotAddParentheses(): void
    {
        $query = (new ListQuery('a', 't'))->andWhere('y = :y', ['y' => 2]);

        self::assertSame('y = :y', $query->where);
    }
}

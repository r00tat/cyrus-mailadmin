<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Criteria;

use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListCriteria::class)]
#[CoversClass(SortDirection::class)]
final class ListCriteriaTest extends TestCase
{
    public function testTheDefaultsAreTheOnesTheContractDeclares(): void
    {
        $criteria = new ListCriteria();

        self::assertSame(1, $criteria->page);
        self::assertSame(50, $criteria->perPage);
        self::assertSame(50, ListCriteria::DEFAULT_PER_PAGE);
        self::assertSame(200, ListCriteria::MAX_PER_PAGE);
        self::assertNull($criteria->sort);
        self::assertSame(SortDirection::Ascending, $criteria->order);
    }

    public function testTheOffsetFollowsFromThePage(): void
    {
        self::assertSame(0, (new ListCriteria(page: 1, perPage: 25))->offset());
        self::assertSame(25, (new ListCriteria(page: 2, perPage: 25))->offset());
        self::assertSame(150, (new ListCriteria(page: 4, perPage: 50))->offset());
    }

    public function testPagesStartAtOne(): void
    {
        $this->expectException(CriteriaError::class);

        new ListCriteria(page: 0);
    }

    public function testTheCeilingIsEnforcedHereAsWellAsInTheSchema(): void
    {
        $this->expectException(CriteriaError::class);
        $this->expectExceptionMessageMatches('/between 1 and 200/');

        new ListCriteria(perPage: 201);
    }

    public function testAPerPageOfZeroIsRefused(): void
    {
        $this->expectException(CriteriaError::class);

        new ListCriteria(perPage: 0);
    }

    public function testFromQueryReadsTheStringsPsr7Hands(): void
    {
        $criteria = ListCriteria::fromQuery([
            'page' => '3',
            'per_page' => '10',
            'sort' => 'name',
            'order' => 'desc',
            'q' => 'example',
        ]);

        self::assertSame(3, $criteria->page);
        self::assertSame(10, $criteria->perPage);
        self::assertSame('name', $criteria->sort);
        self::assertSame(SortDirection::Descending, $criteria->order);
        self::assertSame(['q' => 'example'], $criteria->filters);
    }

    public function testFromQueryUsesTheDefaultsForAbsentValues(): void
    {
        $criteria = ListCriteria::fromQuery([]);

        self::assertSame(1, $criteria->page);
        self::assertSame(50, $criteria->perPage);
        self::assertSame([], $criteria->filters);
    }

    public function testANonNumericPageIsAnErrorRatherThanADefault(): void
    {
        $this->expectException(CriteriaError::class);

        ListCriteria::fromQuery(['page' => 'first']);
    }

    public function testAnUnknownOrderIsAnError(): void
    {
        $this->expectException(CriteriaError::class);

        ListCriteria::fromQuery(['order' => 'sideways']);
    }

    public function testRepeatedQueryParametersBecomeAList(): void
    {
        $criteria = ListCriteria::fromQuery(['format' => ['bcrypt', 'descrypt']]);

        self::assertSame(['format' => ['bcrypt', 'descrypt']], $criteria->filters);
    }

    public function testAFilterCanBeAddedByTheRepositoryLayer(): void
    {
        $criteria = (new ListCriteria())->withFilter('domain', 'example.test');

        self::assertSame(['domain' => 'example.test'], $criteria->filters);
    }
}

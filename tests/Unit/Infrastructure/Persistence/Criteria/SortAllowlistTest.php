<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Criteria;

use Mailadmin\Infrastructure\Persistence\Criteria\ColumnName;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\OrderBy;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(SortAllowlist::class)]
#[CoversClass(OrderBy::class)]
#[CoversClass(ColumnName::class)]
final class SortAllowlistTest extends TestCase
{
    public function testResolvesADeclaredField(): void
    {
        $allowlist = new SortAllowlist(['name' => 'd.domain_name'], default: 'name');

        self::assertSame('d.domain_name', $allowlist->resolve('name')->value);
    }

    public function testFallsBackToTheDefaultWhenNothingIsRequested(): void
    {
        $allowlist = new SortAllowlist(
            ['name' => 'domain_name', 'quota' => 'quota'],
            default: 'quota',
        );

        self::assertSame('quota', $allowlist->resolve(null)->value);
        self::assertSame('quota', $allowlist->resolve('')->value);
    }

    /**
     * The point of the whole package: an unknown field is an error, not a
     * silently ignored parameter and certainly not something that reaches SQL.
     */
    public function testAnUndeclaredFieldIsAnError(): void
    {
        $allowlist = new SortAllowlist(['name' => 'domain_name'], default: 'name');

        $this->expectException(CriteriaError::class);
        $this->expectExceptionMessageMatches('/Cannot sort by "password"/');

        $allowlist->resolve('password');
    }

    #[DataProvider('injectionAttempts')]
    public function testAnInjectionAttemptIsRejectedLikeAnyOtherUnknownField(string $requested): void
    {
        $allowlist = new SortAllowlist(['name' => 'domain_name'], default: 'name');

        $this->expectException(CriteriaError::class);

        $allowlist->resolve($requested);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionAttempts(): iterable
    {
        yield 'statement terminator' => ['domain_name; DROP TABLE accountuser'];
        yield 'union' => ['domain_name UNION SELECT password FROM accountuser'];
        yield 'comment' => ['domain_name -- '];
        yield 'subquery' => ['(SELECT password FROM accountuser LIMIT 1)'];
        yield 'the real column, with a direction glued on' => ['domain_name DESC'];
        yield 'back quote' => ['`domain_name`'];
    }

    public function testAnAllowlistThatIsNotAColumnListIsRefusedAtConstruction(): void
    {
        $this->expectException(CriteriaError::class);
        $this->expectExceptionMessageMatches('/not a plain column name/');

        new SortAllowlist(['name' => 'LOWER(domain_name)'], default: 'name');
    }

    public function testAnEmptyAllowlistIsRefused(): void
    {
        $this->expectException(CriteriaError::class);

        /** @phpstan-ignore argument.type */
        new SortAllowlist([], default: 'name');
    }

    public function testTheDefaultHasToBeInTheList(): void
    {
        $this->expectException(CriteriaError::class);

        new SortAllowlist(['name' => 'domain_name'], default: 'quota');
    }

    public function testTheOrderByFragmentQuotesEveryPart(): void
    {
        $allowlist = new SortAllowlist(['name' => 'd.domain_name'], default: 'name');

        self::assertSame(
            'ORDER BY `d`.`domain_name` ASC',
            OrderBy::fromAllowlist($allowlist, 'name')->toSql(),
        );
    }

    public function testTheTiebreakerIsAppendedSoThatPagingIsStable(): void
    {
        $allowlist = new SortAllowlist(
            ['quota' => 'quota', 'name' => 'domain_name'],
            default: 'name',
            tiebreaker: 'name',
        );

        self::assertSame(
            'ORDER BY `quota` DESC, `domain_name` ASC',
            OrderBy::fromAllowlist($allowlist, 'quota', SortDirection::Descending)->toSql(),
        );
    }

    public function testTheTiebreakerIsNotRepeatedWhenItIsTheSortField(): void
    {
        $allowlist = new SortAllowlist(['name' => 'domain_name'], default: 'name');

        self::assertSame(
            'ORDER BY `domain_name` DESC',
            OrderBy::fromAllowlist($allowlist, 'name', SortDirection::Descending)->toSql(),
        );
    }

    /**
     * The structural guarantee, asserted rather than assumed: there is no public
     * constructor, so an `OrderBy` that never saw an allowlist cannot exist.
     */
    public function testOrderByCannotBeBuiltWithoutAnAllowlist(): void
    {
        $constructor = (new ReflectionClass(OrderBy::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());

        $factories = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(OrderBy::class))->getMethods(\ReflectionMethod::IS_STATIC),
        );

        self::assertSame(['fromAllowlist'], $factories);
    }

    public function testKeysAreTheEnumTheContractHasToDeclare(): void
    {
        $allowlist = new SortAllowlist(
            ['name' => 'domain_name', 'quota' => 'quota'],
            default: 'name',
        );

        self::assertSame(['name', 'quota'], $allowlist->keys());
    }
}

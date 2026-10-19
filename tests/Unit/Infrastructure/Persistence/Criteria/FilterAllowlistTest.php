<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Criteria;

use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\Filters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilterAllowlist::class)]
#[CoversClass(FilterField::class)]
#[CoversClass(Filters::class)]
#[CoversClass(FilterComparison::class)]
final class FilterAllowlistTest extends TestCase
{
    public function testNoFiltersProduceNoSql(): void
    {
        $filters = FilterAllowlist::none()->compile([]);

        self::assertTrue($filters->isEmpty());
        self::assertSame('', $filters->sql);
        self::assertSame([], $filters->bindings);
    }

    public function testAnUndeclaredFilterIsAnErrorRatherThanBeingIgnored(): void
    {
        $allowlist = new FilterAllowlist(new FilterField('name', 'domain_name'));

        $this->expectException(CriteriaError::class);
        $this->expectExceptionMessageMatches('/Cannot filter by "password"/');

        $allowlist->compile(['password' => 'x']);
    }

    public function testEqualityBindsTheValueRatherThanInliningIt(): void
    {
        $filters = (new FilterAllowlist(new FilterField('name', 'd.domain_name')))
            ->compile(['name' => "x' OR 1=1 --"]);

        self::assertSame('`d`.`domain_name` = :ma_f0', $filters->sql);
        self::assertSame(['ma_f0' => "x' OR 1=1 --"], $filters->bindings);
    }

    public function testContainsEscapesTheWildcards(): void
    {
        $filters = (new FilterAllowlist(
            new FilterField('q', 'domain_name', FilterComparison::Contains),
        ))->compile(['q' => 'a_b%c!d\\e']);

        // The escape character is `!` and not a backslash, because a backslash
        // has no spelling that both MariaDB and SQLite accept in this clause:
        // one reads `'\'` as an escaped quote, the other reads `'\\'` as two
        // characters. Asserted as an exact string, because that difference is
        // invisible in anything but the emitted fragment.
        self::assertSame("`domain_name` LIKE :ma_f0 ESCAPE '!'", $filters->sql);

        // Both wildcards neutralised, a literal `!` doubled, and the backslash
        // left exactly as the user typed it.
        self::assertSame(['ma_f0' => '%a!_b!%c!!d\\e%'], $filters->bindings);
    }

    public function testStartsWithAnchorsOnTheLeftOnly(): void
    {
        $filters = (new FilterAllowlist(
            new FilterField('prefix', 'username', FilterComparison::StartsWith),
        ))->compile(['prefix' => 'info']);

        self::assertSame(['ma_f0' => 'info%'], $filters->bindings);
    }

    public function testInProducesOnePlaceholderPerValue(): void
    {
        $filters = (new FilterAllowlist(
            new FilterField('format', 'password_format', FilterComparison::In),
        ))->compile(['format' => ['bcrypt', 'descrypt']]);

        self::assertSame('`password_format` IN (:ma_f0_0, :ma_f0_1)', $filters->sql);
        self::assertSame(['ma_f0_0' => 'bcrypt', 'ma_f0_1' => 'descrypt'], $filters->bindings);
    }

    public function testAnEmptyInListIsRefusedRatherThanGuessed(): void
    {
        $allowlist = new FilterAllowlist(
            new FilterField('format', 'password_format', FilterComparison::In),
        );

        $this->expectException(CriteriaError::class);

        $allowlist->compile(['format' => []]);
    }

    public function testSeveralFiltersAreJoinedWithAnd(): void
    {
        $filters = (new FilterAllowlist(
            new FilterField('name', 'domain_name'),
            new FilterField('active', 'status'),
        ))->compile(['name' => 'example.test', 'active' => 1]);

        self::assertSame('`domain_name` = :ma_f0 AND `status` = :ma_f1', $filters->sql);
        self::assertSame(['ma_f0' => 'example.test', 'ma_f1' => 1], $filters->bindings);
    }

    public function testAnAllowlistThatNamesAnExpressionIsRefused(): void
    {
        $this->expectException(CriteriaError::class);

        new FilterField('name', 'CONCAT(a, b)');
    }

    public function testAListWhereAScalarIsExpectedIsRefused(): void
    {
        $allowlist = new FilterAllowlist(new FilterField('name', 'domain_name'));

        $this->expectException(CriteriaError::class);

        $allowlist->compile(['name' => ['a', 'b']]);
    }

    public function testTheComparisonSetIsClosed(): void
    {
        $allowlist = new FilterAllowlist(
            new FilterField('a', 'a', FilterComparison::NotEquals),
            new FilterField('b', 'b', FilterComparison::GreaterOrEqual),
            new FilterField('c', 'c', FilterComparison::LessOrEqual),
        );

        $filters = $allowlist->compile(['a' => 1, 'b' => 2, 'c' => 3]);

        self::assertSame(
            '`a` <> :ma_f0 AND `b` >= :ma_f1 AND `c` <= :ma_f2',
            $filters->sql,
        );
    }
}

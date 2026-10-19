<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Mailstore;

use Mailadmin\Domain\Exception\InvalidQuota;
use Mailadmin\Domain\Mailstore\Quota;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Quota::class)]
#[CoversClass(InvalidQuota::class)]
final class QuotaTest extends TestCase
{
    public function testUnlimitedIsAStateAndNotTheNumberZero(): void
    {
        $unlimited = Quota::unlimited();
        $zero = Quota::ofKibibytes(0);

        self::assertTrue($unlimited->isUnlimited());
        self::assertNull($unlimited->limitKibibytes());

        self::assertFalse($zero->isUnlimited());
        self::assertSame(0, $zero->limitKibibytes());
        self::assertFalse($unlimited->equals($zero));
    }

    public function testTheLegacyColumnUsesZeroForUnlimited(): void
    {
        self::assertTrue(Quota::fromLegacyKibibytes(0)->isUnlimited());
        self::assertSame(0, Quota::unlimited()->toLegacyKibibytes());
        self::assertSame(20000, Quota::fromLegacyKibibytes(20000)->toLegacyKibibytes());
    }

    public function testTheUnitIsKibibytes(): void
    {
        self::assertSame(1024, Quota::ofMebibytes(1)->limitKibibytes());
        self::assertSame(1048576, Quota::ofGibibytes(1)->limitKibibytes());
    }

    public function testUsageIsOptionalAndNeverConfusedWithZero(): void
    {
        $withoutUsage = Quota::ofMebibytes(100);

        self::assertFalse($withoutUsage->hasUsage());
        self::assertNull($withoutUsage->usedKibibytes());
        self::assertNull($withoutUsage->usagePercentage());
        // "Not measured" must never read as "full".
        self::assertFalse($withoutUsage->isExceeded());
        self::assertTrue($withoutUsage->accommodates(1_000_000));
    }

    public function testUsageIsComparedAgainstTheLimit(): void
    {
        $quota = Quota::ofMebibytes(100, 51200);

        self::assertTrue($quota->hasUsage());
        self::assertSame(50.0, $quota->usagePercentage());
        self::assertFalse($quota->isExceeded());
        self::assertTrue($quota->accommodates(51200));
        self::assertFalse($quota->accommodates(51201));
    }

    public function testAnExceededQuotaIsReported(): void
    {
        $quota = Quota::ofKibibytes(100)->withUsage(150);

        self::assertTrue($quota->isExceeded());
        self::assertSame(150.0, $quota->usagePercentage());
    }

    public function testAZeroLimitDoesNotDivideByZero(): void
    {
        self::assertSame(100.0, Quota::ofKibibytes(0, 1)->usagePercentage());
        self::assertSame(0.0, Quota::ofKibibytes(0, 0)->usagePercentage());
    }

    public function testUnlimitedKnowsItsUsage(): void
    {
        $quota = Quota::unlimited(4096);

        self::assertSame(4096, $quota->usedKibibytes());
        self::assertNull($quota->usagePercentage());
        self::assertFalse($quota->isExceeded());
    }

    public function testNegativeValuesAreRefused(): void
    {
        $this->expectException(InvalidQuota::class);

        Quota::ofKibibytes(-1);
    }

    public function testNegativeUsageIsRefused(): void
    {
        $this->expectException(InvalidQuota::class);

        Quota::unlimited(-1);
    }

    public function testAnOverflowingUnitIsRefused(): void
    {
        $this->expectException(InvalidQuota::class);

        Quota::ofGibibytes(PHP_INT_MAX);
    }
}

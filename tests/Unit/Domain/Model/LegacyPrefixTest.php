<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LegacyPrefix;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The derivation for `domain.prefix` (U-46).
 *
 * The column is `NOT NULL UNIQUE varchar(50)` and nothing reads it, so the only
 * properties worth asserting are the ones the column actually enforces: every
 * candidate has to fit, be non-empty, and differ from the ones before it.
 */
#[CoversClass(LegacyPrefix::class)]
final class LegacyPrefixTest extends TestCase
{
    public function testAShortNameBecomesItsOwnPrefix(): void
    {
        self::assertSame('example.test', LegacyPrefix::preferredFor(self::domain('example.test')));
    }

    public function testTheNameIsLowerCased(): void
    {
        // `domain.domain_name` keeps whatever case it was given; the prefix is
        // ours, and one spelling is one fewer thing to compare.
        self::assertSame('example.test', LegacyPrefix::preferredFor(self::domain('Example.Test')));
    }

    public function testANameThatDoesNotFitIsShortenedAndDiscriminated(): void
    {
        $long = str_repeat('a', 60) . '.example.test';
        $prefix = LegacyPrefix::preferredFor(self::domain($long));

        self::assertNotSame($long, $prefix);
        self::assertLessThanOrEqual(LegacyPrefix::MAX_LENGTH, strlen($prefix));
        self::assertMatchesRegularExpression('/-[0-9a-f]{8}$/', $prefix);
    }

    public function testEveryCandidateFitsTheColumnAndIsUsable(): void
    {
        foreach (['a.test', str_repeat('x', 200) . '.test', 'xn--bcher-kva.test'] as $name) {
            foreach (LegacyPrefix::candidatesFor(self::domain($name)) as $candidate) {
                self::assertNotSame('', $candidate, $name);
                self::assertLessThanOrEqual(LegacyPrefix::MAX_LENGTH, strlen($candidate), $name);
                self::assertTrue(LegacyPrefix::isUsable($candidate), $candidate);
            }
        }
    }

    public function testTheCandidatesAreDistinct(): void
    {
        // The caller walks the list until one is free. Two identical entries
        // would make it try the same taken value twice and give up early.
        $candidates = LegacyPrefix::candidatesFor(self::domain('example.test'));

        self::assertSame($candidates, array_values(array_unique($candidates)));
        self::assertGreaterThanOrEqual(2, count($candidates));
    }

    public function testTheDerivationIsDeterministic(): void
    {
        self::assertSame(
            LegacyPrefix::candidatesFor(self::domain('example.test')),
            LegacyPrefix::candidatesFor(self::domain('example.test')),
        );
    }

    public function testTwoNamesDoNotShareADiscriminatedCandidate(): void
    {
        $left = LegacyPrefix::candidatesFor(self::domain(str_repeat('a', 60) . '.example.test'));
        $right = LegacyPrefix::candidatesFor(self::domain(str_repeat('a', 60) . '.beispiel.invalid'));

        self::assertSame([], array_intersect($left, $right));
    }

    public function testAStoredPrefixWithSpaceInItIsNotUsable(): void
    {
        // Not a check on existing rows — webcyradm's users typed whatever they
        // liked and U-22 keeps those visible. It guards what we write.
        self::assertFalse(LegacyPrefix::isUsable('two words'));
        self::assertFalse(LegacyPrefix::isUsable(''));
        self::assertFalse(LegacyPrefix::isUsable(str_repeat('a', 51)));
    }

    private static function domain(string $name): DomainName
    {
        return DomainName::fromStoredValue($name);
    }
}

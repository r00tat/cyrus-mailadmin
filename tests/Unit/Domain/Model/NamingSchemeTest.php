<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Model\NamingScheme;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamingScheme::class)]
final class NamingSchemeTest extends TestCase
{
    #[DataProvider('storedNames')]
    public function testDetectionAsksExactlyOneQuestion(string $stored, NamingScheme $expected): void
    {
        self::assertSame($expected, NamingScheme::detect($stored));
    }

    /**
     * @return iterable<string, array{string, NamingScheme}>
     */
    public static function storedNames(): iterable
    {
        yield 'no at sign' => ['info.example.test', NamingScheme::Dotted];
        yield 'at sign' => ['info@example.test', NamingScheme::Fqun];
        yield 'dots everywhere, still no at sign' => [
            'a.b.c.d.example.test',
            NamingScheme::Dotted,
        ];
        yield 'at sign inside a long name' => [
            'greta.mustermann@example.test',
            NamingScheme::Fqun,
        ];
    }

    public function testTheThreeStepResolutionPrefersTheDomainSetting(): void
    {
        self::assertSame(
            NamingScheme::Fqun,
            NamingScheme::resolve(NamingScheme::Fqun, NamingScheme::Dotted),
        );
    }

    public function testTheGlobalDefaultAppliesWhenTheDomainSaysNothing(): void
    {
        self::assertSame(
            NamingScheme::Fqun,
            NamingScheme::resolve(null, NamingScheme::Fqun),
        );
    }

    public function testDottedIsTheLastResort(): void
    {
        self::assertSame(NamingScheme::Dotted, NamingScheme::resolve(null, null));
        self::assertSame(NamingScheme::Dotted, NamingScheme::FALLBACK);
    }

    public function testOnlyFqunNeedsALoginRealm(): void
    {
        self::assertTrue(NamingScheme::Fqun->requiresLoginRealm());
        self::assertFalse(NamingScheme::Dotted->requiresLoginRealm());
    }

    public function testTheWireValuesAreStable(): void
    {
        self::assertSame('dotted', NamingScheme::Dotted->value);
        self::assertSame('fqun', NamingScheme::Fqun->value);
    }
}

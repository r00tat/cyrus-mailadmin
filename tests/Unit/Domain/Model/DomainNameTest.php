<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\DomainName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DomainName::class)]
#[CoversClass(InvalidAddress::class)]
final class DomainNameTest extends TestCase
{
    #[DataProvider('acceptedNames')]
    public function testAConventionalNameIsAccepted(string $value): void
    {
        $domain = DomainName::fromString($value);

        self::assertSame($value, $domain->toString());
        self::assertTrue($domain->isConventional());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedNames(): iterable
    {
        yield 'two labels' => ['example.test'];
        yield 'three labels' => ['mail.example.test'];
        yield 'hyphen inside a label' => ['my-example.test'];
        yield 'digits' => ['1example.test'];
        yield 'punycode' => ['xn--mnchen-3ya.test'];
        yield 'mixed case' => ['Example.Test'];
    }

    #[DataProvider('rejectedNames')]
    public function testAnUnconventionalNameIsRefusedForNewDomains(string $value): void
    {
        $this->expectException(InvalidAddress::class);

        DomainName::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedNames(): iterable
    {
        yield 'empty' => [''];
        yield 'single label' => ['localhost'];
        yield 'trailing dot' => ['example.test.'];
        yield 'leading dot' => ['.example.test'];
        yield 'empty label' => ['example..test'];
        yield 'leading hyphen' => ['-example.test'];
        yield 'trailing hyphen' => ['example-.test'];
        yield 'underscore' => ['exa_mple.test'];
        yield 'unicode, must be punycode' => ["m\u{00fc}nchen.test"];
        yield 'contains an at sign' => ['@example.test'];
        yield 'too long' => [str_repeat('a', DomainName::MAX_LENGTH + 1)];
    }

    public function testAStoredNameIsKeptAndFlagged(): void
    {
        $domain = DomainName::fromStoredValue('exa_mple.test');

        self::assertSame('exa_mple.test', $domain->toString());
        self::assertFalse($domain->isConventional());
    }

    public function testAStoredNameWithAnAtSignIsStillRefused(): void
    {
        try {
            DomainName::fromStoredValue('exam@ple.test');
            self::fail('Expected an InvalidAddress.');
        } catch (InvalidAddress $error) {
            self::assertSame(DomainErrorCode::DOMAIN_INVALID_NAME, $error->code());
        }
    }

    public function testPunycodeIsRecognisedAsInternationalised(): void
    {
        self::assertTrue(DomainName::fromString('xn--mnchen-3ya.test')->isInternationalized());
        self::assertFalse(DomainName::fromString('example.test')->isInternationalized());
    }

    public function testComparisonIgnoresCase(): void
    {
        $upper = DomainName::fromString('Example.Test');

        self::assertTrue($upper->equals(DomainName::fromString('example.test')));
        self::assertFalse($upper->isLowerCase());
        $lower = $upper->toLowerCase();

        self::assertSame('example.test', $lower->toString());
        self::assertSame($lower, $lower->toLowerCase());
    }

    public function testLabelsAndLengthAreReadable(): void
    {
        $domain = DomainName::fromString('mail.example.test');

        self::assertSame(['mail', 'example', 'test'], $domain->labels());
        self::assertSame(17, $domain->length());
    }
}

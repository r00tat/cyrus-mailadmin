<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\LocalPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailAddress::class)]
final class EmailAddressTest extends TestCase
{
    public function testAnAddressIsBuiltFromItsTwoParts(): void
    {
        $address = new EmailAddress(
            LocalPart::fromString('greta.mustermann'),
            DomainName::fromString('example.test'),
        );

        self::assertSame('greta.mustermann@example.test', $address->toString());
        self::assertTrue($address->isConventional());
    }

    public function testParsingSplitsAtTheLastAtSign(): void
    {
        // A local part must not contain an "@", so the separator is the last
        // one. This is allowed here and forbidden for Cyrus user names — there
        // the dot is ambiguous, here the "@" is not.
        $address = EmailAddress::parse('greta.mustermann@example.test');

        self::assertSame('greta.mustermann', $address->localPart->toString());
        self::assertSame('example.test', $address->domain->toString());
    }

    #[DataProvider('nonAddresses')]
    public function testAValueWithoutAUsableSeparatorIsRefused(string $value): void
    {
        try {
            EmailAddress::parse($value);
            self::fail('Expected an InvalidAddress.');
        } catch (InvalidAddress $error) {
            self::assertSame(DomainErrorCode::ADDRESS_INVALID, $error->code());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonAddresses(): iterable
    {
        yield 'no at sign' => ['info.example.test'];
        yield 'nothing in front' => ['@example.test'];
        yield 'nothing behind' => ['info@'];
        yield 'empty' => [''];
    }

    #[DataProvider('addressShapes')]
    public function testLooksLikeAddressIsTheCheapPreCheck(string $value, bool $expected): void
    {
        self::assertSame($expected, EmailAddress::looksLikeAddress($value));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addressShapes(): iterable
    {
        yield 'address' => ['info@example.test', true];
        yield 'dotted cyrus user name' => ['info.example.test', false];
        yield 'catchall' => ['@example.test', false];
        yield 'trailing at sign' => ['info@', false];
    }

    public function testAStoredAddressKeepsAnUnconventionalLocalPart(): void
    {
        $address = EmailAddress::parseStored('f*o*o@example.test');

        self::assertSame('f*o*o@example.test', $address->toString());
        self::assertFalse($address->isConventional());
    }

    public function testTheBaseAddressDropsTheDetail(): void
    {
        $address = EmailAddress::parse('info+shop@example.test');

        self::assertSame('info@example.test', $address->baseAddress()->toString());
    }

    public function testAnAddressWithoutADetailIsItsOwnBase(): void
    {
        $address = EmailAddress::parse('info@example.test');

        self::assertSame($address, $address->baseAddress());
    }

    public function testComparisonIgnoresCase(): void
    {
        $address = EmailAddress::parse('Info@Example.Test');

        self::assertTrue($address->equals(EmailAddress::parse('info@example.test')));
        self::assertSame('info@example.test', $address->toLowerCase()->toString());
        self::assertTrue($address->isInDomain(DomainName::fromString('example.test')));
        self::assertFalse($address->isInDomain(DomainName::fromString('other.test')));
    }
}

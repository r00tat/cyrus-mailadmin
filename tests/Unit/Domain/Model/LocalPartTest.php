<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\LocalPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalPart::class)]
#[CoversClass(InvalidAddress::class)]
final class LocalPartTest extends TestCase
{
    #[DataProvider('acceptedValues')]
    public function testAConventionalLocalPartIsAccepted(string $value): void
    {
        self::assertSame($value, LocalPart::fromString($value)->toString());
        self::assertTrue(LocalPart::fromString($value)->isConventional());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'simple' => ['info'];
        yield 'with a dot' => ['greta.mustermann'];
        yield 'with several dots' => ['a.b.c'];
        yield 'with a hyphen' => ['mailer-daemon'];
        yield 'with an underscore' => ['no_reply'];
        yield 'with a recipient delimiter' => ['info+shop'];
        yield 'digits only' => ['12345'];
        yield 'mixed case' => ['Greta.Mustermann'];
        yield 'at the length limit' => [str_repeat('a', LocalPart::MAX_LENGTH)];
    }

    #[DataProvider('rejectedValues')]
    public function testAnUnconventionalLocalPartIsRefusedForNewAddresses(string $value): void
    {
        $this->expectException(InvalidAddress::class);

        LocalPart::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'leading dot' => ['.info'];
        yield 'trailing dot' => ['info.'];
        yield 'two dots in a row' => ['in..fo'];
        yield 'one dot' => ['.'];
        yield 'contains an at sign' => ['info@example'];
        yield 'contains a slash' => ['in/fo'];
        yield 'contains a space' => ['in fo'];
        yield 'the legacy asterisk style' => ['f*o*o+b*a*r'];
        yield 'one byte over the limit' => [str_repeat('a', LocalPart::MAX_LENGTH + 1)];
    }

    public function testAStoredValueIsKeptAndFlagged(): void
    {
        // U-22: the legacy row stays readable, and the interface can say why it
        // stands out. No new address may look like this.
        $localPart = LocalPart::fromStoredValue('f*o*o+b*a*r');

        self::assertSame('f*o*o+b*a*r', $localPart->toString());
        self::assertFalse($localPart->isConventional());
    }

    public function testAStoredValueMayExceedTheAddressLimitButNotTheColumn(): void
    {
        $long = str_repeat('a', LocalPart::MAX_LENGTH + 10);

        self::assertFalse(LocalPart::fromStoredValue($long)->isConventional());

        $this->expectException(InvalidAddress::class);
        LocalPart::fromStoredValue(str_repeat('a', LocalPart::MAX_STORED_LENGTH + 1));
    }

    public function testAStoredValueWithAnAtSignIsStillRefused(): void
    {
        // An "@" can only ever be the domain separator — a local part carrying
        // one would make every derived form wrong.
        try {
            LocalPart::fromStoredValue('in@fo');
            self::fail('Expected an InvalidAddress.');
        } catch (InvalidAddress $error) {
            self::assertSame(DomainErrorCode::ADDRESS_INVALID_LOCAL_PART, $error->code());
        }
    }

    public function testTheRecipientDelimiterIsKnownButNeverAppliedOnItsOwn(): void
    {
        $localPart = LocalPart::fromString('info+shop');

        self::assertSame('shop', $localPart->detail());
        self::assertSame('info', $localPart->withoutDetail()->toString());
        // The value itself is untouched: which address a mail is routed to is
        // Postfix's business, and a lookup that dropped the detail silently
        // would find the wrong row.
        self::assertSame('info+shop', $localPart->toString());
    }

    public function testALocalPartWithoutADetailIsItsOwnBase(): void
    {
        $localPart = LocalPart::fromString('info');

        self::assertNull($localPart->detail());
        self::assertSame($localPart, $localPart->withoutDetail());
    }

    public function testALeadingDelimiterIsNotADetail(): void
    {
        $localPart = LocalPart::fromString('+info');

        self::assertNull($localPart->detail());
        self::assertSame('+info', $localPart->withoutDetail()->toString());
    }

    public function testComparisonIgnoresCaseAndLoweringKeepsTheFlag(): void
    {
        $upper = LocalPart::fromString('Greta.Mustermann');

        self::assertTrue($upper->equals(LocalPart::fromString('greta.mustermann')));
        self::assertFalse($upper->isLowerCase());
        self::assertSame('greta.mustermann', $upper->toLowerCase()->toString());
        self::assertSame(16, $upper->length());
        self::assertTrue($upper->containsDot());
    }

    public function testValuesAreTrimmed(): void
    {
        self::assertSame('info', LocalPart::fromString("  info\n")->toString());
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\DottedNamespace;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DottedNamespace::class)]
final class DottedNamespaceTest extends TestCase
{
    public function testADottedLocalPartReachesIntoEverySubdomainItsTailsName(): void
    {
        $identity = MailboxIdentity::create(
            LocalPart::fromString('a.b.c'),
            DomainName::fromString('example.test'),
            NamingScheme::Dotted,
        );

        self::assertSame(
            ['b.c.example.test', 'c.example.test'],
            self::names(DottedNamespace::subdomainsReachedBy($identity)),
        );
    }

    public function testALocalPartWithoutDotsReachesNothing(): void
    {
        $identity = MailboxIdentity::create(
            LocalPart::fromString('anna'),
            DomainName::fromString('example.test'),
            NamingScheme::Dotted,
        );

        self::assertSame([], DottedNamespace::subdomainsReachedBy($identity));
    }

    public function testTheFullyQualifiedSchemeReachesNothing(): void
    {
        $identity = MailboxIdentity::create(
            LocalPart::fromString('info.kunde'),
            DomainName::fromString('example.test'),
            NamingScheme::Fqun,
        );

        self::assertSame([], DottedNamespace::subdomainsReachedBy($identity));
    }

    public function testTheParentsOfADomainAreItsProperSuffixes(): void
    {
        self::assertSame(
            ['b.example.test', 'example.test', 'test'],
            self::names(DottedNamespace::parentsOf(DomainName::fromStoredValue('a.b.example.test'))),
        );
    }

    /**
     * @param list<DomainName> $names
     *
     * @return list<string>
     */
    private static function names(array $names): array
    {
        return array_map(static fn (DomainName $name): string => $name->toString(), $names);
    }
}

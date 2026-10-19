<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Domain\Exception\InvalidMailboxIdentity;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(MailboxIdentity::class)]
#[CoversClass(NamingScheme::class)]
#[CoversClass(InvalidMailboxIdentity::class)]
final class MailboxIdentityTest extends TestCase
{
    public function testTheConstructorIsPrivateSoNobodyCanBuildAnIdentityFromAUserNameAlone(): void
    {
        // The one structural guarantee of this class: without the domain there
        // is no way in. `local.domain.tld` cannot be split, so a constructor
        // taking a single string would be a bug waiting for a local part with
        // a dot in it.
        $constructor = (new ReflectionClass(MailboxIdentity::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testDottedSchemeDerivesEveryForm(): void
    {
        $identity = self::identity('greta.mustermann', 'example.test', NamingScheme::Dotted);

        self::assertSame('greta.mustermann@example.test', $identity->address()->toString());
        self::assertSame('greta.mustermann.example.test', $identity->cyrusUsername());
        self::assertSame('user/greta.mustermann.example.test', $identity->imapMailbox());
        self::assertSame('greta^mustermann^example^test', $identity->spoolPath());
    }

    public function testFqunSchemeDerivesEveryForm(): void
    {
        $identity = self::identity('greta.mustermann', 'example.test', NamingScheme::Fqun);

        self::assertSame('greta.mustermann@example.test', $identity->address()->toString());
        self::assertSame('greta.mustermann@example.test', $identity->cyrusUsername());
        self::assertSame('user/greta.mustermann@example.test', $identity->imapMailbox());
        self::assertSame(
            'domain/e/example.test/g/user/greta^mustermann',
            $identity->spoolPath(),
        );
    }

    public function testTheAddressIsTheSameInBothSchemes(): void
    {
        $dotted = self::identity('info', 'example.test', NamingScheme::Dotted);
        $fqun = self::identity('info', 'example.test', NamingScheme::Fqun);

        self::assertSame($dotted->address()->toString(), $fqun->address()->toString());
        self::assertNotSame($dotted->cyrusUsername(), $fqun->cyrusUsername());
    }

    public function testAFolderKeepsTheDomainAtTheEndOfTheFqunMailboxName(): void
    {
        // With virtual domains the domain closes the whole mailbox name. Get
        // this wrong and the folder is created where nobody sees it.
        $fqun = self::identity('greta.mustermann', 'example.test', NamingScheme::Fqun);
        $dotted = self::identity('greta.mustermann', 'example.test', NamingScheme::Dotted);

        self::assertSame('user/greta.mustermann/Sent@example.test', $fqun->imapFolder('Sent'));
        self::assertSame(
            'user/greta.mustermann.example.test/Sent',
            $dotted->imapFolder('Sent'),
        );
    }

    public function testASpoolHashFallsBackWhenTheNameDoesNotStartWithALetter(): void
    {
        $identity = self::identity('1st.contact', '1example.test', NamingScheme::Fqun);

        self::assertSame(
            'domain/q/1example.test/q/user/1st^contact',
            $identity->spoolPath(),
        );
    }

    #[DataProvider('storedUsernames')]
    public function testTheSchemeIsRecognisedFromTheStoredUserName(
        string $storedUsername,
        string $domain,
        NamingScheme $expectedScheme,
        string $expectedLocalPart,
    ): void {
        $identity = MailboxIdentity::fromStoredUsername(
            $storedUsername,
            DomainName::fromString($domain),
        );

        self::assertSame($expectedScheme, $identity->scheme);
        self::assertSame($expectedLocalPart, $identity->localPart->toString());
        self::assertSame($domain, $identity->domain->toString());
    }

    /**
     * @return iterable<string, array{string, string, NamingScheme, string}>
     */
    public static function storedUsernames(): iterable
    {
        yield 'dotted, simple' => [
            'info.example.test',
            'example.test',
            NamingScheme::Dotted,
            'info',
        ];

        yield 'dotted, local part with a dot' => [
            'greta.mustermann.example.test',
            'example.test',
            NamingScheme::Dotted,
            'greta.mustermann',
        ];

        yield 'dotted, local part that contains the domain name' => [
            // What the legacy view allusers gets wrong: REPLACE removes every
            // occurrence of ".example.test" and leaves "info".
            'info.example.test.example.test',
            'example.test',
            NamingScheme::Dotted,
            'info.example.test',
        ];

        yield 'dotted, local part equal to the domain name' => [
            'example.test.example.test',
            'example.test',
            NamingScheme::Dotted,
            'example.test',
        ];

        yield 'fqun, simple' => [
            'info@example.test',
            'example.test',
            NamingScheme::Fqun,
            'info',
        ];

        yield 'fqun, local part with a dot' => [
            'greta.mustermann@example.test',
            'example.test',
            NamingScheme::Fqun,
            'greta.mustermann',
        ];

        yield 'fqun, local part that contains the domain name' => [
            'info.example.test@example.test',
            'example.test',
            NamingScheme::Fqun,
            'info.example.test',
        ];

        yield 'dotted, subdomain' => [
            'info.mail.example.test',
            'mail.example.test',
            NamingScheme::Dotted,
            'info',
        ];
    }

    #[DataProvider('roundTrips')]
    public function testGeneratingAndRecognisingAreInverse(
        string $localPart,
        string $domain,
        NamingScheme $scheme,
    ): void {
        $generated = self::identity($localPart, $domain, $scheme);

        $recognised = MailboxIdentity::fromStoredUsername(
            $generated->cyrusUsername(),
            DomainName::fromString($domain),
        );

        self::assertTrue($generated->equals($recognised));
        self::assertSame($generated->cyrusUsername(), $recognised->cyrusUsername());
        self::assertSame($generated->address()->toString(), $recognised->address()->toString());
        self::assertSame($generated->spoolPath(), $recognised->spoolPath());
    }

    /**
     * @return iterable<string, array{string, string, NamingScheme}>
     */
    public static function roundTrips(): iterable
    {
        foreach ([NamingScheme::Dotted, NamingScheme::Fqun] as $scheme) {
            yield 'plain, ' . $scheme->value => ['info', 'example.test', $scheme];
            yield 'dotted local part, ' . $scheme->value => [
                'greta.mustermann',
                'example.test',
                $scheme,
            ];
            yield 'local part contains the domain, ' . $scheme->value => [
                'info.example.test',
                'example.test',
                $scheme,
            ];
            yield 'plus detail, ' . $scheme->value => ['info+shop', 'example.test', $scheme];
        }
    }

    public function testRecognitionReproducesTheStoredNameByteForByte(): void
    {
        // accountuser.username is a `varchar binary`: a generated name that
        // differs in case addresses a different row and renames a different
        // mailbox.
        $stored = 'Greta.Mustermann.Example.Test';

        $identity = MailboxIdentity::fromStoredUsername(
            $stored,
            DomainName::fromStoredValue('example.test'),
        );

        self::assertSame($stored, $identity->cyrusUsername());
        self::assertTrue($identity->matchesStoredUsername('greta.mustermann.example.test'));
    }

    public function testADottedNameThatDoesNotEndInTheDomainIsRefused(): void
    {
        $this->expectException(InvalidMailboxIdentity::class);

        MailboxIdentity::fromStoredUsername(
            'info.other.test',
            DomainName::fromString('example.test'),
        );
    }

    public function testAFqunNameWithAnotherDomainIsRefused(): void
    {
        try {
            MailboxIdentity::fromStoredUsername(
                'info@other.test',
                DomainName::fromString('example.test'),
            );
            self::fail('Expected an InvalidMailboxIdentity.');
        } catch (InvalidMailboxIdentity $error) {
            self::assertSame(DomainErrorCode::ACCOUNT_IDENTITY_MISMATCH, $error->code());
        }
    }

    public function testANameThatIsNothingButTheDomainIsRefused(): void
    {
        $this->expectException(InvalidMailboxIdentity::class);

        MailboxIdentity::fromStoredUsername(
            '@example.test',
            DomainName::fromString('example.test'),
        );
    }

    public function testAnEmptyStoredNameIsRefused(): void
    {
        try {
            MailboxIdentity::fromStoredUsername('   ', DomainName::fromString('example.test'));
            self::fail('Expected an InvalidMailboxIdentity.');
        } catch (InvalidMailboxIdentity $error) {
            self::assertSame(DomainErrorCode::ACCOUNT_INVALID_IDENTITY, $error->code());
        }
    }

    public function testTheNameIsNotSplitByReplace(): void
    {
        // Belt and braces for the fragility described in architecture 3.3: a
        // naive str_replace produces "info", the suffix cut produces the truth.
        $stored = 'info.example.test.example.test';
        $identity = MailboxIdentity::fromStoredUsername(
            $stored,
            DomainName::fromString('example.test'),
        );

        self::assertSame('info', str_replace('.example.test', '', $stored));
        self::assertSame('info.example.test', $identity->localPart->toString());
    }

    public function testChangingTheSchemeKeepsTheAddress(): void
    {
        $dotted = self::identity('greta.mustermann', 'example.test', NamingScheme::Dotted);
        $fqun = $dotted->withScheme(NamingScheme::Fqun);

        self::assertSame($dotted->address()->toString(), $fqun->address()->toString());
        self::assertSame('greta.mustermann@example.test', $fqun->cyrusUsername());
        self::assertFalse($dotted->equals($fqun));
        self::assertSame($dotted, $dotted->withScheme(NamingScheme::Dotted));
    }

    public function testChangingTheDomainKeepsTheLocalPartAndScheme(): void
    {
        $identity = self::identity('greta.mustermann', 'example.test', NamingScheme::Dotted)
            ->withDomain(DomainName::fromString('example.invalid'));

        self::assertSame('greta.mustermann.example.invalid', $identity->cyrusUsername());
        self::assertSame(NamingScheme::Dotted, $identity->scheme);
    }

    private static function identity(
        string $localPart,
        string $domain,
        NamingScheme $scheme,
    ): MailboxIdentity {
        return MailboxIdentity::create(
            LocalPart::fromString($localPart),
            DomainName::fromString($domain),
            $scheme,
        );
    }
}

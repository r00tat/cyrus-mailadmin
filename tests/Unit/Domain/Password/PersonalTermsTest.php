<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Password;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Password\PersonalTerms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalTerms::class)]
final class PersonalTermsTest extends TestCase
{
    public function testAMailboxContributesItsLocalPartItsDomainAndTheirPieces(): void
    {
        $terms = PersonalTerms::forMailbox($this->identity());

        self::assertContains('greta.mustermann', $terms->all());
        self::assertContains('greta', $terms->all());
        self::assertContains('mustermann', $terms->all());
        self::assertContains('example.test', $terms->all());
        self::assertContains('example', $terms->all());
        self::assertContains('test', $terms->all());
    }

    public function testTheMatchIsCaseInsensitiveAndReturnsTheTermThatMatched(): void
    {
        $terms = PersonalTerms::forMailbox($this->identity());

        self::assertSame('mustermann', $terms->matchIn('xxMustermann2026xx'));
        self::assertSame('example', $terms->matchIn('EXAMPLE-is-my-password'));
        self::assertNull($terms->matchIn('an-unrelated-passphrase'));
    }

    /**
     * Without the threshold a domain such as `example.at` would forbid every
     * password containing "at", and a local part of `pw` almost everything.
     */
    #[DataProvider('shortPieces')]
    public function testPiecesShorterThanTheThresholdAreDropped(string $piece): void
    {
        self::assertNotContains($piece, PersonalTerms::of('pw@ex.at')->all());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortPieces(): iterable
    {
        yield 'two-letter local part' => ['pw'];
        yield 'two-letter label' => ['ex'];
        yield 'country code' => ['at'];
    }

    public function testTheThresholdIsFourCharacters(): void
    {
        self::assertSame(4, PersonalTerms::MINIMUM_TERM_LENGTH);
        self::assertContains('abcd', PersonalTerms::of('abcd')->all());
        self::assertNotContains('abc', PersonalTerms::of('abc')->all());
    }

    public function testAnEmptySetMatchesNothing(): void
    {
        self::assertTrue(PersonalTerms::none()->isEmpty());
        self::assertNull(PersonalTerms::none()->matchIn('anything at all'));
    }

    /**
     * A purely numeric local part must survive de-duplication. It would not if
     * the terms were kept as array keys: PHP turns `'1234'` into the integer
     * 1234 and `str_contains()` would then be handed an int.
     */
    public function testANumericTermIsKeptAsAString(): void
    {
        $terms = PersonalTerms::of('123456@example.test');

        self::assertContains('123456', $terms->all());
        self::assertSame('123456', $terms->matchIn('my123456password'));
    }

    public function testTermsAreDeduplicated(): void
    {
        $terms = PersonalTerms::of('example.test', 'example.test', 'example');

        self::assertSame(count($terms->all()), count(array_unique($terms->all())));
    }

    public function testAnAdminContributesOnlyTheUserName(): void
    {
        $terms = PersonalTerms::forAdmin('postmaster');

        self::assertSame(['postmaster'], $terms->all());
    }

    public function testAnAddressCanBeUsedWithoutAnIdentity(): void
    {
        $terms = PersonalTerms::forAddress(
            LocalPart::fromString('greta.mustermann'),
            DomainName::fromString('example.test'),
        );

        self::assertSame('mustermann', $terms->matchIn('mustermann-and-more'));
    }

    public function testWithAddsFurtherTerms(): void
    {
        $terms = PersonalTerms::forAdmin('postmaster')->with('mailadmin');

        self::assertContains('postmaster', $terms->all());
        self::assertContains('mailadmin', $terms->all());
    }

    private function identity(): MailboxIdentity
    {
        return MailboxIdentity::create(
            LocalPart::fromString('greta.mustermann'),
            DomainName::fromString('example.test'),
            NamingScheme::Dotted,
        );
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Password\PersonalTerms;
use Mailadmin\Infrastructure\Security\PasswordGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordGenerator::class)]
final class PasswordGeneratorTest extends TestCase
{
    #[DataProvider('subjects')]
    public function testAGeneratedPasswordSatisfiesTheSamePolicyAsATypedOne(PasswordSubject $subject): void
    {
        $policy = new PasswordPolicy(new InMemoryCommonPasswordList());
        $generated = (new PasswordGenerator($policy))->generate($subject);

        self::assertTrue($policy->isAcceptable($generated->reveal(), $subject));
        self::assertSame($subject, $generated->subject());
    }

    /**
     * @return iterable<string, array{PasswordSubject}>
     */
    public static function subjects(): iterable
    {
        foreach (PasswordSubject::cases() as $subject) {
            yield $subject->value => [$subject];
        }
    }

    /**
     * The alphabet leaves out the five characters people actually confuse when
     * a password is dictated over the telephone or copied off a printed letter.
     */
    #[DataProvider('ambiguousCharacters')]
    public function testTheAlphabetHasNoAmbiguousCharacters(string $character): void
    {
        self::assertStringNotContainsString($character, PasswordGenerator::ALPHABET);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousCharacters(): iterable
    {
        yield 'zero' => ['0'];
        yield 'capital o' => ['O'];
        yield 'one' => ['1'];
        yield 'lower L' => ['l'];
        yield 'capital i' => ['I'];
    }

    public function testTheAlphabetHasNoDuplicates(): void
    {
        $characters = str_split(PasswordGenerator::ALPHABET);

        self::assertSame($characters, array_values(array_unique($characters)));
        self::assertSame(57, strlen(PasswordGenerator::ALPHABET));
    }

    public function testTheShapeIsFourGroupsOfFour(): void
    {
        $generated = $this->generator()->generate(PasswordSubject::AccountUser)->reveal();

        self::assertMatchesRegularExpression(
            sprintf(
                '/^([%s]{4}-){3}[%s]{4}$/',
                preg_quote(PasswordGenerator::ALPHABET, '/'),
                preg_quote(PasswordGenerator::ALPHABET, '/'),
            ),
            $generated,
        );
        self::assertSame(19, strlen($generated));
    }

    /**
     * Long enough for the stricter of the two subjects, and far short of the
     * 72-byte bcrypt limit.
     */
    public function testTheLengthClearsBothMinimumsAndTheBcryptLimit(): void
    {
        $generated = $this->generator()->generate(PasswordSubject::AdminUser);

        self::assertGreaterThanOrEqual(PasswordSubject::AdminUser->minimumLength(), $generated->length());
        self::assertLessThanOrEqual(72, $generated->lengthInBytes());
    }

    public function testEveryGeneratedPasswordIsDifferent(): void
    {
        $generator = $this->generator();
        $seen = [];

        for ($run = 0; $run < 50; ++$run) {
            $seen[] = $generator->generate(PasswordSubject::AccountUser)->reveal();
        }

        self::assertCount(50, array_unique($seen));
    }

    /**
     * Over 500 draws every character of the alphabet should appear at least
     * once — a crude but effective check that the alphabet is really being used
     * and no index is off by one.
     */
    public function testEveryCharacterOfTheAlphabetIsReachable(): void
    {
        $generator = $this->generator();
        $seen = '';

        for ($run = 0; $run < 500; ++$run) {
            $seen .= str_replace(
                PasswordGenerator::GROUP_SEPARATOR,
                '',
                $generator->generate(PasswordSubject::AccountUser)->reveal(),
            );
        }

        foreach (str_split(PasswordGenerator::ALPHABET) as $character) {
            self::assertStringContainsString($character, $seen);
        }
    }

    public function testAGeneratedPasswordIsCheckedAgainstThePersonalTermsToo(): void
    {
        $generated = $this->generator()->generate(
            PasswordSubject::AccountUser,
            PersonalTerms::of('greta.mustermann@example.test'),
        );

        self::assertNull(
            PersonalTerms::of('greta.mustermann@example.test')->matchIn($generated->reveal()),
        );
    }

    private function generator(): PasswordGenerator
    {
        return new PasswordGenerator(new PasswordPolicy(new InMemoryCommonPasswordList()));
    }
}

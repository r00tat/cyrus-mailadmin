<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Sieve;

use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Domain\Exception\InvalidSieveRule;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Sieve\ConditionJoin;
use Mailadmin\Domain\Sieve\FilterCondition;
use Mailadmin\Domain\Sieve\FilterRule;
use Mailadmin\Domain\Sieve\MatchType;
use Mailadmin\Domain\Sieve\RedirectRule;
use Mailadmin\Domain\Sieve\SieveRuleSet;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Domain\Sieve\VacationRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SieveRuleSet::class)]
#[CoversClass(SieveScript::class)]
#[CoversClass(VacationRule::class)]
#[CoversClass(FilterRule::class)]
#[CoversClass(FilterCondition::class)]
#[CoversClass(InvalidSieveRule::class)]
final class SieveRuleSetTest extends TestCase
{
    public function testAnEmptyRuleSetKnowsItIsEmpty(): void
    {
        $rules = SieveRuleSet::empty();

        self::assertTrue($rules->isEmpty());
        self::assertFalse($rules->hasVacation());
        self::assertFalse($rules->hasRedirects());
        self::assertFalse($rules->hasFilters());
        self::assertSame([], $rules->requiredExtensions());
        self::assertTrue($rules->keepsLocalCopy());
    }

    public function testEveryChangeProducesANewRuleSet(): void
    {
        $rules = SieveRuleSet::empty();
        $withVacation = $rules->withVacation(VacationRule::of('Away.'));

        self::assertTrue($rules->isEmpty());
        self::assertFalse($withVacation->isEmpty());
        self::assertNull($rules->vacation());
        self::assertNotNull($withVacation->vacation());
        self::assertTrue($withVacation->withVacation(null)->isEmpty());
    }

    public function testTheSameRedirectIsNotAddedTwice(): void
    {
        $redirect = new RedirectRule(EmailAddress::parse('elsewhere@other.test'));

        $rules = SieveRuleSet::empty()->withRedirect($redirect)->withRedirect(
            new RedirectRule(EmailAddress::parse('ELSEWHERE@other.test')),
        );

        self::assertCount(1, $rules->redirects());
    }

    public function testRedirectsCanBeReplacedWholesale(): void
    {
        $rules = SieveRuleSet::empty()
            ->withRedirect(new RedirectRule(EmailAddress::parse('a@other.test')))
            ->withRedirects([new RedirectRule(EmailAddress::parse('b@other.test'))]);

        self::assertCount(1, $rules->redirects());
        self::assertSame('b@other.test', $rules->redirects()[0]->destination->toString());
    }

    public function testTheRequiredExtensionsAreSortedAndUnique(): void
    {
        $rules = SieveRuleSet::empty()
            ->withVacation(VacationRule::of('Away.'))
            ->withFilter(FilterRule::fileInto(
                'A',
                ConditionJoin::All,
                FilterCondition::subjectContains('a'),
            ))
            ->withFilter(FilterRule::fileInto(
                'B',
                ConditionJoin::All,
                FilterCondition::subjectContains('b'),
            ));

        self::assertSame(['fileinto', 'vacation'], $rules->requiredExtensions());
        self::assertCount(2, $rules->filters());
    }

    public function testAVacationRuleNeedsAMessage(): void
    {
        $this->expectException(InvalidSieveRule::class);

        VacationRule::of('   ');
    }

    public function testTheVacationIntervalIsBounded(): void
    {
        try {
            VacationRule::of('Away.', null, 0);
            self::fail('Expected an InvalidSieveRule.');
        } catch (InvalidSieveRule $error) {
            self::assertSame(DomainErrorCode::SIEVE_INVALID_RULE, $error->code());
        }

        $this->expectException(InvalidSieveRule::class);
        VacationRule::of('Away.', null, VacationRule::MAX_INTERVAL_DAYS + 1);
    }

    public function testAnEmptySubjectIsTheSameAsNoSubject(): void
    {
        $rule = VacationRule::of('Away.', '   ');

        self::assertFalse($rule->hasSubject());
        self::assertNull($rule->subject);
        self::assertSame(VacationRule::DEFAULT_INTERVAL_DAYS, $rule->intervalDays);
        self::assertSame([], $rule->addressStrings());
    }

    public function testAFilterNeedsAtLeastOneCondition(): void
    {
        $this->expectException(InvalidSieveRule::class);

        FilterRule::discard(ConditionJoin::All);
    }

    public function testFileIntoNeedsAFolder(): void
    {
        $this->expectException(InvalidSieveRule::class);

        FilterRule::fileInto('   ', ConditionJoin::All, FilterCondition::subjectContains('x'));
    }

    public function testAConditionNeedsAUsableHeaderAndAValue(): void
    {
        try {
            FilterCondition::on('sub ject', MatchType::Is, 'x');
            self::fail('Expected an InvalidSieveRule.');
        } catch (InvalidSieveRule $error) {
            self::assertSame(DomainErrorCode::SIEVE_INVALID_RULE, $error->code());
        }

        $this->expectException(InvalidSieveRule::class);
        FilterCondition::on('subject', MatchType::Is, '');
    }

    public function testAForeignScriptIsRecognisedAndNotOurs(): void
    {
        // Architecture 9.4: a script without our marker is never overwritten.
        $foreign = SieveScript::of('sieve', "require [\"vacation\"];\r\nkeep;\r\n");

        self::assertTrue($foreign->isForeign());
        self::assertFalse($foreign->isManaged());
    }

    public function testAMarkerFurtherDownDoesNotCountAsOurs(): void
    {
        $body = str_repeat('x', 300) . SieveScript::markerLine();

        self::assertTrue(SieveScript::of('sieve', $body)->isForeign());
    }

    public function testAnEmptyScriptIsRecognised(): void
    {
        self::assertTrue(SieveScript::of('sieve', "  \r\n")->isEmpty());
    }

    public function testAScriptNameThatWouldBreakTheProtocolIsRefused(): void
    {
        $this->expectException(InvalidSieveRule::class);

        SieveScript::of("sieve\r\nlogout", 'x');
    }

    public function testAnEmptyScriptNameIsRefused(): void
    {
        $this->expectException(InvalidSieveRule::class);

        SieveScript::of('  ', 'x');
    }
}

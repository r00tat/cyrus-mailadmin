<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Sieve;

use Mailadmin\Domain\Exception\InvalidSieveRule;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Sieve\ConditionJoin;
use Mailadmin\Domain\Sieve\FilterAction;
use Mailadmin\Domain\Sieve\FilterCondition;
use Mailadmin\Domain\Sieve\FilterRule;
use Mailadmin\Domain\Sieve\MatchType;
use Mailadmin\Domain\Sieve\RedirectRule;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Domain\Sieve\SieveRuleSet;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Domain\Sieve\VacationRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SieveRenderer::class)]
#[CoversClass(SieveRuleSet::class)]
#[CoversClass(SieveScript::class)]
#[CoversClass(VacationRule::class)]
#[CoversClass(RedirectRule::class)]
#[CoversClass(FilterRule::class)]
#[CoversClass(FilterCondition::class)]
#[CoversClass(FilterAction::class)]
#[CoversClass(InvalidSieveRule::class)]
final class SieveRendererTest extends TestCase
{
    private SieveRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SieveRenderer();
    }

    public function testAnEmptyRuleSetStillCarriesTheMarker(): void
    {
        $script = $this->renderer->render(SieveRuleSet::empty());

        self::assertSame(SieveScript::LEGACY_NAME, $script->name);
        self::assertTrue($script->isManaged());
        self::assertStringStartsWith('# mailadmin-managed: v1', $script->content);
        self::assertStringNotContainsString('require', $script->content);
    }

    public function testVacationAloneRendersARequireAndAMultilineMessage(): void
    {
        $rules = SieveRuleSet::empty()->withVacation(VacationRule::of(
            "I am away.\nBack on Monday.",
            'Out of office',
            3,
            [EmailAddress::parse('office@example.test')],
        ));

        $content = $this->renderer->renderContent($rules);

        self::assertStringContainsString('require ["vacation"];', $content);
        self::assertStringContainsString(
            'vacation :days 3 :subject "Out of office" '
            . ':addresses ["office@example.test"] text:',
            $content,
        );
        self::assertStringContainsString("I am away.\r\nBack on Monday.\r\n.\r\n;", $content);
    }

    public function testSpecialCharactersInSubjectAndBodySurvive(): void
    {
        $rules = SieveRuleSet::empty()->withVacation(VacationRule::of(
            "Gr\u{00fc}\u{00df}e \"aus\" M\u{00fc}nchen\nund ein \\ Backslash",
            'Antwort: "abwesend"',
        ));

        $content = $this->renderer->renderContent($rules);

        // Quoted strings escape backslash and quotation mark, nothing else.
        self::assertStringContainsString(':subject "Antwort: \"abwesend\""', $content);
        // The body is a literal, so it is not escaped at all.
        self::assertStringContainsString("Gr\u{00fc}\u{00df}e \"aus\" M\u{00fc}nchen", $content);
        self::assertStringContainsString('und ein \\ Backslash', $content);
    }

    public function testALineConsistingOfADotIsStuffed(): void
    {
        // Otherwise it would end the literal in the middle of the message.
        $rules = SieveRuleSet::empty()->withVacation(VacationRule::of("before\n.\nafter"));

        $content = $this->renderer->renderContent($rules);

        self::assertStringContainsString("before\r\n..\r\nafter\r\n.\r\n;", $content);
    }

    public function testARedirectKeepsACopyOnlyWhenAskedTo(): void
    {
        $rules = SieveRuleSet::empty()
            ->withRedirect(new RedirectRule(EmailAddress::parse('elsewhere@other.test')));

        $withCopy = $this->renderer->renderContent($rules);
        $withoutCopy = $this->renderer->renderContent($rules->withLocalCopy(false));

        self::assertStringContainsString('redirect "elsewhere@other.test";', $withCopy);
        // redirect cancels the implicit keep — without the explicit line the
        // mailbox would keep nothing.
        self::assertStringContainsString("redirect \"elsewhere@other.test\";\r\nkeep;", $withCopy);
        self::assertStringNotContainsString('keep;', $withoutCopy);
    }

    public function testAFilterRendersConditionsActionAndStop(): void
    {
        $rules = SieveRuleSet::empty()->withFilter(FilterRule::fileInto(
            'Junk',
            ConditionJoin::Any,
            FilterCondition::subjectContains('[SPAM]'),
            FilterCondition::fromContains('spammer@other.test'),
        ));

        $content = $this->renderer->renderContent($rules);

        self::assertStringContainsString('require ["fileinto"];', $content);
        self::assertStringContainsString(
            'if anyof (header :contains "subject" "[SPAM]", '
            . 'header :contains "from" "spammer@other.test") {',
            $content,
        );
        self::assertStringContainsString('    fileinto "Junk";', $content);
        self::assertStringContainsString('    stop;', $content);
    }

    public function testASingleConditionIsRenderedWithoutTheJoin(): void
    {
        $rules = SieveRuleSet::empty()->withFilter(FilterRule::discard(
            ConditionJoin::All,
            FilterCondition::on('x-spam-flag', MatchType::Is, 'YES'),
        )->continuingAfterMatch());

        $content = $this->renderer->renderContent($rules);

        self::assertStringContainsString('if header :is "x-spam-flag" "YES" {', $content);
        self::assertStringContainsString('    discard;', $content);
        self::assertStringNotContainsString('stop;', $content);
        self::assertStringNotContainsString('require', $content);
    }

    public function testEverythingTogetherKeepsTheOrderThatMatters(): void
    {
        $rules = SieveRuleSet::of(
            VacationRule::of('Away.'),
            [new RedirectRule(EmailAddress::parse('elsewhere@other.test'))],
            [FilterRule::fileInto('Junk', ConditionJoin::All, FilterCondition::subjectContains('x'))],
        );

        $content = $this->renderer->renderContent($rules);

        $require = strpos($content, 'require ');
        $filter = strpos($content, 'fileinto');
        $redirect = strpos($content, 'redirect ');
        $vacation = strpos($content, 'vacation ');

        self::assertIsInt($require);
        self::assertIsInt($filter);
        self::assertIsInt($redirect);
        self::assertIsInt($vacation);
        self::assertLessThan($filter, $require);
        self::assertLessThan($redirect, $filter);
        self::assertLessThan($vacation, $redirect);
        self::assertSame(['fileinto', 'vacation'], $rules->requiredExtensions());
    }

    public function testRenderingIsDeterministic(): void
    {
        $rules = SieveRuleSet::empty()->withVacation(VacationRule::of('Away.'));

        self::assertSame(
            $this->renderer->renderContent($rules),
            $this->renderer->renderContent($rules),
        );
    }

    public function testLinesEndWithCrLf(): void
    {
        $content = $this->renderer->renderContent(SieveRuleSet::empty());

        self::assertStringEndsWith(SieveRenderer::EOL, $content);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $content));
    }
}

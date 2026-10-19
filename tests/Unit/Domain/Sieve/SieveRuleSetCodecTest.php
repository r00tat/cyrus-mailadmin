<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Sieve;

use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Sieve\ConditionJoin;
use Mailadmin\Domain\Sieve\FilterCondition;
use Mailadmin\Domain\Sieve\FilterRule;
use Mailadmin\Domain\Sieve\MatchType;
use Mailadmin\Domain\Sieve\RedirectRule;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Domain\Sieve\SieveRuleSet;
use Mailadmin\Domain\Sieve\SieveRuleSetCodec;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Domain\Sieve\VacationRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The model line (U-102): what the renderer writes under the marker, and the
 * only thing a managed script is read back from.
 */
#[CoversClass(SieveRuleSetCodec::class)]
#[CoversClass(SieveRenderer::class)]
#[CoversClass(SieveScript::class)]
final class SieveRuleSetCodecTest extends TestCase
{
    public function testEveryPartOfARuleSetSurvivesTheRoundTrip(): void
    {
        $rules = SieveRuleSet::of(
            VacationRule::of(
                "Gr\u{00fc}\u{00df}e,\nich bin \"weg\" \\ bis Montag.",
                'Abwesend',
                14,
                [EmailAddress::parse('anna@example.test'), EmailAddress::parse('info@example.test')],
            ),
            [new RedirectRule(EmailAddress::parse('boss@other.test'))],
            [
                FilterRule::fileInto(
                    'Archiv/Rechnungen',
                    ConditionJoin::Any,
                    FilterCondition::subjectContains('Rechnung'),
                    FilterCondition::on('X-Spam-Flag', MatchType::Is, 'YES'),
                ),
                FilterRule::discard(ConditionJoin::All, FilterCondition::fromContains('spam@')),
            ],
            false,
        );

        $decoded = SieveRuleSetCodec::decode(SieveRuleSetCodec::encode($rules));

        self::assertNotNull($decoded);
        self::assertSame(SieveRuleSetCodec::encode($rules), SieveRuleSetCodec::encode($decoded));
        self::assertSame("Gr\u{00fc}\u{00df}e,\nich bin \"weg\" \\ bis Montag.", $decoded->vacation()?->message);
        self::assertSame(['anna@example.test', 'info@example.test'], $decoded->vacation()->addressStrings());
        self::assertFalse($decoded->keepsLocalCopy());
        self::assertCount(2, $decoded->filters());
    }

    public function testTheEncodingIsOneLineOfBase64Url(): void
    {
        $encoded = SieveRuleSetCodec::encode(SieveRuleSet::empty()->withVacation(VacationRule::of(str_repeat("Zeile\r\n", 200))));

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
    }

    public function testTheRendererWritesTheModelLineDirectlyUnderTheMarker(): void
    {
        $rules = SieveRuleSet::empty()->withVacation(VacationRule::of('Bin weg.'));
        $script = (new SieveRenderer())->render($rules);

        $lines = preg_split('/\r\n/', $script->content);
        self::assertIsArray($lines);
        self::assertSame(SieveScript::markerLine(), $lines[0]);
        self::assertStringStartsWith(SieveRuleSetCodec::MODEL_PREFIX, $lines[1]);

        self::assertSame('Bin weg.', $script->ruleSet()?->vacation()?->message);
    }

    public function testAForeignScriptHasNoRuleSetEvenWithAModelLine(): void
    {
        $model = SieveRuleSetCodec::encode(SieveRuleSet::empty()->withVacation(VacationRule::of('x')));
        $script = SieveScript::of('roundcube', "# rule\n" . SieveRuleSetCodec::MODEL_PREFIX . $model . "\nkeep;");

        self::assertTrue($script->isForeign());
        self::assertNull($script->ruleSet());
    }

    public function testAManagedScriptWithoutAModelLineIsUnreadableNotEmpty(): void
    {
        $script = SieveScript::of('sieve', SieveScript::markerLine() . "\r\nkeep;\r\n");

        self::assertTrue($script->isManaged());
        self::assertNull($script->ruleSet());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64url' => ['abc+/='];
        yield 'not json' => [rtrim(strtr(base64_encode('{nope'), '+/', '-_'), '=')];
        yield 'wrong version' => [self::encodeJson(['v' => 2, 'redirects' => [], 'filters' => [], 'keep' => true, 'vacation' => null])];
        yield 'json scalar' => [self::encodeJson(['v' => 1, 'redirects' => 'x'])];
        yield 'bad redirect' => [self::encodeJson(['v' => 1, 'redirects' => ['no-at-sign'], 'filters' => [], 'keep' => true, 'vacation' => null])];
        yield 'bad interval' => [self::encodeJson(['v' => 1, 'redirects' => [], 'filters' => [], 'keep' => true, 'vacation' => ['message' => 'x', 'subject' => null, 'days' => 0, 'addresses' => []]])];
        yield 'empty message' => [self::encodeJson(['v' => 1, 'redirects' => [], 'filters' => [], 'keep' => true, 'vacation' => ['message' => ' ', 'subject' => null, 'days' => 7, 'addresses' => []]])];
        yield 'bad match type' => [self::encodeJson(['v' => 1, 'redirects' => [], 'keep' => true, 'vacation' => null, 'filters' => [['conditions' => [['header' => 'Subject', 'match' => ':regex', 'value' => 'x']], 'join' => 'anyof', 'action' => 'discard', 'folder' => null, 'stop' => true]]])];
        yield 'keep not bool' => [self::encodeJson(['v' => 1, 'redirects' => [], 'filters' => [], 'keep' => 'yes', 'vacation' => null])];
    }

    #[DataProvider('garbage')]
    public function testAnythingThatIsNotAValidModelDecodesToNull(string $encoded): void
    {
        self::assertNull(SieveRuleSetCodec::decode($encoded));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodeJson(array $data): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    }
}

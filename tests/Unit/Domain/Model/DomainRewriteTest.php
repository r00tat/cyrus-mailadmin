<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Model;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\DomainRewrite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a rename rewrites in `virtual`, and — the half that matters more — what
 * it refuses to touch.
 *
 * The refusals are the interesting assertions here. A destination this class
 * leaves alone is a forward that A6's reconciliation will report; one it
 * rewrites wrongly delivers somebody's mail to a stranger.
 */
#[CoversClass(DomainRewrite::class)]
final class DomainRewriteTest extends TestCase
{
    public function testAnAddressAliasMovesToTheNewDomain(): void
    {
        self::assertSame('info@new.test', self::rewrite()->alias('info@old.test'));
    }

    public function testACatchallMovesToo(): void
    {
        // `@domain` is the catchall spelling in `virtual.alias` (architecture 3.4).
        self::assertSame('@new.test', self::rewrite()->alias('@old.test'));
    }

    public function testALocalPartContainingTheDomainNameIsNotMangled(): void
    {
        // The suffix is removed by length, once, from the end. A `REPLACE` —
        // which is what the `allusers` view does — would eat the middle too.
        self::assertSame('old.test.info@new.test', self::rewrite()->alias('old.test.info@old.test'));
    }

    public function testAnAliasInAnotherDomainIsLeftAlone(): void
    {
        self::assertNull(self::rewrite()->alias('info@other.test'));
        self::assertNull(self::rewrite()->alias('@other.test'));
    }

    public function testASubdomainIsNotTheDomain(): void
    {
        // `sub.old.test` ends with `old.test` but not with `@old.test`.
        self::assertNull(self::rewrite()->alias('info@sub.old.test'));
    }

    public function testTheDomainPartIsMatchedWithoutRegardToCase(): void
    {
        self::assertSame('info@new.test', self::rewrite()->alias('info@OLD.TEST'));
    }

    public function testADestinationAddressInTheDomainMoves(): void
    {
        self::assertSame('info@new.test', self::rewrite()->destination('info@old.test'));
    }

    public function testADottedUserNameIsOnlyRewrittenWhenItWasLookedUp(): void
    {
        // `info.old.test` could be `info` in `old.test` or `info.old` in
        // `test` — the string cannot say (architecture 3.3). Only the caller,
        // holding `accountuser`, can.
        self::assertNull(self::rewrite()->destination('info.old.test'));
        self::assertSame(
            'info.new.test',
            self::rewrite()->destination('info.old.test', ['info.old.test' => 'info.new.test']),
        );
    }

    public function testALookedUpNameIsMatchedWithoutRegardToCase(): void
    {
        self::assertSame(
            'info.new.test',
            self::rewrite()->destination('Info.Old.Test', ['info.old.test' => 'info.new.test']),
        );
    }

    public function testAListKeepsItsOrderAndOnlyMovesWhatBelongsToTheDomain(): void
    {
        self::assertSame(
            'keep@other.test,info@new.test,also@elsewhere.invalid',
            self::rewrite()->destinations('keep@other.test, info@old.test, also@elsewhere.invalid'),
        );
    }

    public function testAListThatMentionsNothingOfOursIsReportedAsUnchanged(): void
    {
        // `null` rather than the same string back: the caller skips the UPDATE,
        // which is what keeps a rename from rewriting every row of `virtual`.
        self::assertNull(self::rewrite()->destinations('a@other.test,b@elsewhere.invalid'));
        self::assertNull(self::rewrite()->destinations(''));
    }

    public function testAValueThatIsNotAnAddressAtAllSurvivesUntouched(): void
    {
        self::assertNull(self::rewrite()->destination('| /usr/bin/procmail'));
    }

    public function testARenameToTheSameNameIsANoop(): void
    {
        $same = new DomainRewrite(self::domain('old.test'), self::domain('old.test'));

        self::assertTrue($same->isNoop());
    }

    public function testAChangeOfSpellingIsNotANoop(): void
    {
        // Cyrus and `accountuser.username` store the exact bytes, so changing
        // the case really does move mailboxes.
        $cased = new DomainRewrite(self::domain('Old.test'), self::domain('old.test'));

        self::assertFalse($cased->isNoop());
    }

    private static function rewrite(): DomainRewrite
    {
        return new DomainRewrite(self::domain('old.test'), self::domain('new.test'));
    }

    private static function domain(string $value): DomainName
    {
        return DomainName::fromStoredValue($value);
    }
}

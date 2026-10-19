<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support\Mailstore;

use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Sieve\RedirectRule;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Domain\Sieve\SieveRuleSet;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Domain\Sieve\VacationRule;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every {@see SieveStore} has to show, run against each of them —
 * M3's fourth Definition-of-Done item.
 *
 * The reason is the one M2 gave for the mailstore contract, and it is stronger
 * here. Every vacation and forwarding use case will be tested against the
 * double; a double that is more forgiving than timsieved turns a green suite
 * into a promise the server does not keep. The three places where that is easiest
 * to get wrong are all in this file:
 *
 *  - **storing does not activate** — the separation that lets a server reject a
 *    script *before* it becomes the one filtering mail;
 *  - **the active script cannot be deleted** — every ManageSieve server refuses
 *    it, so the double must too;
 *  - **the content comes back byte for byte** — architecture 9.4 renders whole
 *    and writes whole, and a store that normalised anything on the way through
 *    would hide a renderer that produces something the server would refuse.
 *
 * The scripts written here are **valid Sieve**, not placeholder text. Against
 * the double that is irrelevant; against timsieved it is the difference between
 * a contract test and a test of the server's parser, because `PUTSCRIPT` checks
 * the script before storing it.
 *
 * Only the `dotted` naming scheme is exercised. ManageSieve never sees a mailbox
 * name — it sees the account name that {@see MailboxIdentity::cyrusUsername()}
 * produces, and that method is already put through both schemes by
 * {@see MailstoreContract}.
 */
abstract class SieveStoreContract extends TestCase
{
    /** A second script beside the managed one, to have something inactive. */
    protected const string SPARE_NAME = 'spare';

    /**
     * A store with no scripts for the identities below. Called once per test.
     */
    abstract protected function emptyStore(): SieveStore;

    /**
     * The domain the identities live in.
     *
     * Overridable because "empty" differs: the double starts empty by
     * construction, a real Cyrus is shared with F5's fixtures and can only be
     * given a corner of itself.
     */
    protected static function domain(): string
    {
        return 'example.test';
    }

    // -----------------------------------------------------------------
    // Nothing there
    // -----------------------------------------------------------------

    public function testAnAccountWithoutScriptsHasNothingActive(): void
    {
        $store = $this->emptyStore();

        self::assertNull($store->activeScript($this->anna()));
        self::assertSame([], $store->scriptNames($this->anna()));
    }

    public function testDeletingSomethingThatIsNotThereSucceeds(): void
    {
        $store = $this->emptyStore();

        $store->deleteScript($this->anna(), self::SPARE_NAME);

        self::assertSame([], $store->scriptNames($this->anna()));
    }

    /**
     * The postcondition — "nothing is running" — already holds. A repair run
     * (A6) has to be able to finish rather than trip over its own earlier work.
     */
    public function testDeactivatingWhenNothingRunsSucceeds(): void
    {
        $store = $this->emptyStore();

        $store->deactivate($this->anna());

        self::assertNull($store->activeScript($this->anna()));
    }

    // -----------------------------------------------------------------
    // Storing and activating
    // -----------------------------------------------------------------

    public function testStoringAScriptDoesNotActivateIt(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->managedScript());

        self::assertSame([SieveScript::LEGACY_NAME], $store->scriptNames($this->anna()));
        self::assertNull($store->activeScript($this->anna()));

        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        self::assertSame(
            SieveScript::LEGACY_NAME,
            $store->activeScript($this->anna())?->name,
        );
    }

    /**
     * Umlauts, quotation marks, an apostrophe and newlines, through a real
     * vacation message — M3's Definition of Done, and its fourth open point.
     *
     * The whole path is octets: the renderer writes UTF-8, the client announces a
     * byte count in `{n+}` and the server returns the same count. Nothing
     * converts, which is why nothing can turn an umlaut into a question mark the
     * way the legacy system's `iconv` could.
     */
    public function testAMessageWithUmlautsQuotesAndNewlinesSurvivesUnchanged(): void
    {
        $store = $this->emptyStore();
        $script = $this->managedScript($this->awkwardRules());

        $store->putScript($this->anna(), $script);
        $store->activate($this->anna(), $script->name);

        $active = $store->activeScript($this->anna());

        self::assertNotNull($active);
        self::assertSame($script->content, $active->content);
        self::assertStringContainsString('Grüße', $active->content);
        self::assertStringContainsString('"Urlaub"', $active->content);
    }

    public function testOurOwnScriptIsRecognisedAsOurs(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->managedScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        $active = $store->activeScript($this->anna());

        self::assertNotNull($active);
        self::assertTrue($active->isManaged());
        self::assertFalse($active->isForeign());
    }

    /**
     * Architecture 9.4's second rule, at the level where it can be observed: an
     * active script without the marker — webcyradm's, or one written by hand —
     * is *recognisable*. Refusing to overwrite it is
     * {@see \Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller}'s job; being
     * able to tell is this one's.
     */
    public function testAForeignActiveScriptIsRecognisedAsForeign(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->foreignScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        $active = $store->activeScript($this->anna());

        self::assertNotNull($active);
        self::assertTrue($active->isForeign());
        self::assertFalse($active->isManaged());
    }

    public function testStoringUnderAnExistingNameReplacesIt(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->foreignScript());
        $store->putScript($this->anna(), $this->managedScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        self::assertSame([SieveScript::LEGACY_NAME], $store->scriptNames($this->anna()));
        self::assertTrue($store->activeScript($this->anna())?->isManaged());
    }

    public function testAStoredScriptCanBeReadByNameWhetherActiveOrNot(): void
    {
        // U-113: what `putScript()` would replace must be visible first.
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->foreignScript());

        $inactive = $store->script($this->anna(), SieveScript::LEGACY_NAME);
        self::assertNotNull($inactive);
        self::assertTrue($inactive->isForeign());
        self::assertSame($this->foreignScript()->content, $inactive->content);
        self::assertNull($store->activeScript($this->anna()));

        self::assertNull($store->script($this->anna(), self::SPARE_NAME));
        self::assertNull($store->script($this->bruno(), SieveScript::LEGACY_NAME));
    }

    public function testActivatingSomethingThatIsNotThereIsRefused(): void
    {
        $store = $this->emptyStore();

        try {
            $store->activate($this->anna(), self::SPARE_NAME);
            self::fail('Activating a script that does not exist must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_SCRIPT_NOT_FOUND, $failure->errorCode());
            self::assertFalse($failure->isRetryable());
        }
    }

    // -----------------------------------------------------------------
    // Withdrawing
    // -----------------------------------------------------------------

    public function testTheActiveScriptCannotBeDeleted(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->managedScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        try {
            $store->deleteScript($this->anna(), SieveScript::LEGACY_NAME);
            self::fail('Deleting the active script must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_REJECTED, $failure->errorCode());
        }

        self::assertSame([SieveScript::LEGACY_NAME], $store->scriptNames($this->anna()));
    }

    public function testAnInactiveScriptCanBeDeleted(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->managedScript());
        $store->putScript($this->anna(), $this->spareScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        $store->deleteScript($this->anna(), self::SPARE_NAME);

        self::assertSame([SieveScript::LEGACY_NAME], $store->scriptNames($this->anna()));
    }

    /**
     * Deactivate, then delete. Without the first step there is no way to
     * withdraw a rule set at all — the reason `deactivate()` is on the port.
     */
    public function testAScriptCanBeWithdrawnCompletely(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->managedScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        $store->deactivate($this->anna());
        $store->deleteScript($this->anna(), SieveScript::LEGACY_NAME);

        self::assertNull($store->activeScript($this->anna()));
        self::assertSame([], $store->scriptNames($this->anna()));
    }

    /**
     * Two accounts do not share a script namespace. A store that let them would
     * make the worst bug in this package invisible — one user's out-of-office
     * notice installed into another user's mailbox.
     */
    public function testScriptsBelongToOneAccount(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->managedScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        self::assertSame([], $store->scriptNames($this->bruno()));
        self::assertNull($store->activeScript($this->bruno()));
    }

    // -----------------------------------------------------------------

    protected function managedScript(?SieveRuleSet $rules = null): SieveScript
    {
        return (new SieveRenderer())->render(
            $rules ?? SieveRuleSet::of(vacation: VacationRule::of('Ich bin nicht da.')),
            SieveScript::LEGACY_NAME,
        );
    }

    /**
     * A valid script that carries no marker — what webcyradm left behind, or
     * what a user wrote in Roundcube.
     */
    protected function foreignScript(): SieveScript
    {
        return SieveScript::of(
            SieveScript::LEGACY_NAME,
            "# hand-written, not by mailadmin\r\nkeep;\r\n",
        );
    }

    protected function spareScript(): SieveScript
    {
        return SieveScript::of(self::SPARE_NAME, "# spare\r\nkeep;\r\n");
    }

    /**
     * Everything the transport has to survive in one rule set: umlauts, an
     * escaped quotation mark, a line starting with a dot, several newlines and a
     * redirect.
     */
    protected function awkwardRules(): SieveRuleSet
    {
        return SieveRuleSet::of(
            vacation: VacationRule::of(
                message: "Liebe Grüße,\r\nich bin im \"Urlaub\" – bis 1. März.\r\n"
                    . ".. und danach auch nicht sofort erreichbar.\r\nÄÖÜ ß",
                subject: 'Abwesenheit: "Urlaub"',
                addresses: [EmailAddress::parse('anna@' . static::domain())],
            ),
            redirects: [new RedirectRule(EmailAddress::parse('bruno@' . static::domain()))],
        );
    }

    protected function anna(): MailboxIdentity
    {
        return static::identity('anna');
    }

    protected function bruno(): MailboxIdentity
    {
        return static::identity('bruno');
    }

    protected static function identity(string $localPart): MailboxIdentity
    {
        return MailboxIdentity::create(
            LocalPart::fromString($localPart),
            DomainName::fromString(static::domain()),
            NamingScheme::Dotted,
        );
    }
}

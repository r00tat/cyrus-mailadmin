<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore;

use Mailadmin\Domain\Mailstore\MailstoreFailure;
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
use Mailadmin\Infrastructure\Mailstore\InMemorySieveStore;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveCapabilities;
use Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two rules of architecture 9.4, at the level where they are enforced rather
 * than merely recognisable.
 *
 * The foreign-script case is the one M3 exists for. The legacy system pushed
 * out-of-office and forwarding into one script with regular expressions; this
 * one refuses to touch a script it did not write until somebody says so in
 * words.
 */
#[CoversClass(SieveScriptInstaller::class)]
final class SieveScriptInstallerTest extends TestCase
{
    public function testARuleSetBecomesTheActiveScriptInOneStep(): void
    {
        $scripts = new InMemorySieveStore();
        $installer = $this->installer($scripts);

        $script = $installer->install($this->anna(), $this->rules());

        $active = $scripts->activeScript($this->anna());

        self::assertNotNull($script);
        self::assertNotNull($active);
        self::assertSame(SieveScript::LEGACY_NAME, $script->name);
        self::assertTrue($active->isManaged());
        self::assertSame($script->content, $active->content);
    }

    /**
     * Store first, activate second — so that a script the server rejects is
     * rejected before it becomes the one filtering incoming mail.
     */
    public function testTheScriptIsStoredBeforeItIsActivated(): void
    {
        $scripts = new InMemorySieveStore();

        $this->installer($scripts)->install($this->anna(), $this->rules());

        self::assertSame([
            'put anna.example.test sieve',
            'activate anna.example.test sieve',
        ], $scripts->operations());
    }

    public function testAForeignInactiveScriptUnderOurNameIsNeitherReplacedNorDeleted(): void
    {
        // Review finding on A3: only the active script was looked at, and
        // `PUTSCRIPT sieve` replaced whatever was stored under that name.
        $scripts = new InMemorySieveStore();
        $foreign = SieveScript::of(SieveScript::LEGACY_NAME, "# by hand\r\nkeep;\r\n");
        $scripts->givenStoredScript($this->anna(), $foreign);

        foreach ([
            fn () => $this->installer($scripts)->install($this->anna(), $this->rules()),
            fn () => $this->installer($scripts)->install($this->anna(), SieveRuleSet::empty()),
            fn () => $this->installer($scripts)->remove($this->anna()),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('A script mailadmin did not write must not be replaced or deleted.');
            } catch (MailstoreFailure $failure) {
                self::assertSame(ErrorCode::SIEVE_SCRIPT_FOREIGN, $failure->errorCode());
            }
        }

        self::assertSame($foreign->content, $scripts->script($this->anna(), SieveScript::LEGACY_NAME)?->content);

        $this->installer($scripts)->install($this->anna(), $this->rules(), takeOverForeignScript: true);
        self::assertTrue($scripts->activeScript($this->anna())?->isManaged());
    }

    public function testAForeignActiveScriptIsNotOverwritten(): void
    {
        $scripts = new InMemorySieveStore();
        $scripts->givenForeignScript($this->anna(), "# by hand\r\nkeep;\r\n");
        $scripts->forget();

        try {
            $this->installer($scripts)->install($this->anna(), $this->rules());
            self::fail('A script mailadmin did not write must not be overwritten.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_SCRIPT_FOREIGN, $failure->errorCode());
            self::assertFalse($failure->isRetryable());
        }

        self::assertSame([], $scripts->operations(), 'something was written anyway');
        self::assertTrue($scripts->activeScript($this->anna())?->isForeign());
    }

    /**
     * The takeover is a parameter of the operation, not a setting of the
     * installation: consent belongs to one act, not to a deployment.
     */
    public function testAForeignScriptIsOverwrittenWhenTheTakeoverIsGiven(): void
    {
        $scripts = new InMemorySieveStore();
        $scripts->givenForeignScript($this->anna(), "# by hand\r\nkeep;\r\n");

        $this->installer($scripts)->install($this->anna(), $this->rules(), takeOverForeignScript: true);

        self::assertTrue($scripts->activeScript($this->anna())?->isManaged());
    }

    public function testOurOwnScriptIsReplacedWithoutBeingAskedAboutIt(): void
    {
        $scripts = new InMemorySieveStore();
        $installer = $this->installer($scripts);
        $installer->install($this->anna(), $this->rules());

        $second = $installer->install(
            $this->anna(),
            SieveRuleSet::of(vacation: VacationRule::of('Ich bin wieder weg.')),
        );

        self::assertNotNull($second);
        self::assertStringContainsString('Ich bin wieder weg.', $second->content);
    }

    /**
     * An empty rule set has no script. Leaving an empty one active would be
     * indistinguishable, to the next reader, from a script that failed to
     * render.
     */
    public function testAnEmptyRuleSetWithdrawsTheScript(): void
    {
        $scripts = new InMemorySieveStore();
        $installer = $this->installer($scripts);
        $installer->install($this->anna(), $this->rules());

        $result = $installer->install($this->anna(), SieveRuleSet::empty());

        self::assertNull($result);
        self::assertNull($scripts->activeScript($this->anna()));
        self::assertSame([], $scripts->scriptNames($this->anna()));
    }

    public function testRemoveWithdrawsTheScriptAndRefusesAForeignOne(): void
    {
        $scripts = new InMemorySieveStore();
        $installer = $this->installer($scripts);
        $installer->install($this->anna(), $this->rules());

        $installer->remove($this->anna());

        self::assertNull($scripts->activeScript($this->anna()));

        $scripts->givenForeignScript($this->anna(), "# by hand\r\nkeep;\r\n");

        $this->expectExceptionMessage('not written by mailadmin');

        $installer->remove($this->anna());
    }

    /**
     * M3's open point 2. A script requiring an extension the server does not
     * implement is rejected **in full** — the forwards go down with the vacation
     * notice — so the question is asked before anything is written.
     */
    public function testAnExtensionTheServerDoesNotHaveStopsTheInstallBeforeItStarts(): void
    {
        $scripts = new InMemorySieveStore();
        $installer = new SieveScriptInstaller(
            $scripts,
            new SieveRenderer(),
            $this->serverWith('fileinto'),
        );

        try {
            $installer->install($this->anna(), $this->rules());
            self::fail('A rule set needing an unavailable extension must not be written.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_EXTENSION_MISSING, $failure->errorCode());
            self::assertStringContainsString('vacation', $failure->getMessage());
        }

        self::assertSame([], $scripts->operations());
    }

    public function testAnInstallProceedsWhenTheServerAnnouncesWhatIsNeeded(): void
    {
        $scripts = new InMemorySieveStore();
        $installer = new SieveScriptInstaller(
            $scripts,
            new SieveRenderer(),
            $this->serverWith('fileinto', 'vacation'),
        );

        $installer->install($this->anna(), $this->rules());

        self::assertTrue($scripts->activeScript($this->anna())?->isManaged());
    }

    public function testTheForeignScriptIsAvailableForAReadOnlyView(): void
    {
        $scripts = new InMemorySieveStore();
        $installer = $this->installer($scripts);

        self::assertNull($installer->foreignScript($this->anna()));

        $scripts->givenForeignScript($this->anna(), "# by hand\r\nkeep;\r\n");

        self::assertSame(
            "# by hand\r\nkeep;\r\n",
            $installer->foreignScript($this->anna())?->content,
        );

        $installer->install($this->anna(), $this->rules(), takeOverForeignScript: true);

        self::assertNull($installer->foreignScript($this->anna()));
    }

    // -----------------------------------------------------------------

    private function installer(InMemorySieveStore $scripts): SieveScriptInstaller
    {
        return new SieveScriptInstaller($scripts, new SieveRenderer());
    }

    /**
     * A server that announces exactly these extensions.
     */
    private function serverWith(string ...$extensions): SieveCapabilities
    {
        return new class (array_values($extensions)) implements SieveCapabilities {
            /**
             * @param list<string> $extensions
             */
            public function __construct(private readonly array $extensions)
            {
            }

            /**
             * @return list<string>
             */
            public function sieveExtensions(MailboxIdentity $identity): array
            {
                return $this->extensions;
            }
        };
    }

    private function rules(): SieveRuleSet
    {
        return SieveRuleSet::of(
            vacation: VacationRule::of('Ich bin im Urlaub.'),
            redirects: [new RedirectRule(EmailAddress::parse('bruno@example.test'))],
        );
    }

    private function anna(): MailboxIdentity
    {
        return MailboxIdentity::create(
            LocalPart::fromString('anna'),
            DomainName::fromString('example.test'),
            NamingScheme::Dotted,
        );
    }
}

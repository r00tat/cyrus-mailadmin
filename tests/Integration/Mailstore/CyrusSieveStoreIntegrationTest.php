<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Mailstore;

use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Domain\Sieve\SieveRuleSet;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Domain\Sieve\VacationRule;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusMailstore;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusSieveStore;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\StreamTransportFactory;
use Mailadmin\Infrastructure\Mailstore\Net\TlsMode;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveTrace;
use Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller;
use Mailadmin\Tests\Integration\TestEnvironment\RequiresTestEnvironment;
use Mailadmin\Tests\Integration\TestEnvironment\TestEnvironment;
use Mailadmin\Tests\Support\Mailstore\SieveStoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The same contract as {@see \Mailadmin\Tests\Unit\Infrastructure\Mailstore\InMemorySieveStoreContractTest},
 * against a real timsieved — which is what makes the double worth testing use
 * cases against.
 *
 * **It works in a corner of the server.** F5's fixtures live in `example.test`
 * and `beispiel.invalid`; this suite uses `leer.test`, the domain the fixtures
 * deliberately leave empty, creates its two mailboxes in `setUp()` and removes
 * them again. A Sieve space belongs to an account, so the account has to exist
 * before there is anything to write into.
 *
 * The connection is `STARTTLS` on 4190, because that is the only encrypted path
 * timsieved has — and it exists only because F5 sets `allowstarttls: yes`, which
 * Cyrus does not (U-38 a, **O-14**). If that line were missing, every case here
 * would fail with "the server does not offer STARTTLS", which is the message the
 * client gives for exactly this reason.
 */
#[CoversClass(CyrusSieveStore::class)]
#[CoversClass(ManageSieveConnectionPool::class)]
#[CoversClass(SieveScriptInstaller::class)]
final class CyrusSieveStoreIntegrationTest extends SieveStoreContract
{
    use RequiresTestEnvironment;

    /** No fixture account lives here. See docker/db/fixtures/20-synthetic-data.sql. */
    private const string EMPTY_DOMAIN = 'leer.test';

    /** Everything a case in this file can leave behind. */
    private const array SCRIPT_NAMES = [SieveScript::LEGACY_NAME, self::SPARE_NAME];

    private ?ManageSieveConnectionPool $sievePool = null;

    private ?ConnectionPool $imapPool = null;

    /**
     * Did `setUp()` get far enough for there to be anything to clean up? PHPUnit
     * runs `tearDown()` even after a skip, and reaching for the server there
     * would turn every skip into an error.
     */
    private bool $ready = false;

    protected static function domain(): string
    {
        return self::EMPTY_DOMAIN;
    }

    /**
     * Local parts nothing else in the suite touches.
     *
     * `leer.test` is the empty domain, but it is not *this* test's domain:
     * {@see \Mailadmin\Tests\Integration\Mailstore\CyrusMailstoreIntegrationTest}
     * works there too and removes `anna`, `bruno` and four other names in its own
     * setUp. Two suites sharing a name against one server is a test that fails
     * only when both run, which is the worst kind — so the accounts here carry a
     * prefix that only this file produces.
     */
    protected static function identity(string $localPart): MailboxIdentity
    {
        return parent::identity('m3.' . $localPart);
    }

    protected function setUp(): void
    {
        $this->requireTestEnvironment();
        $this->requireAVerifiableHostName();
        $this->requireTheTestCertificateAuthority();

        $this->ready = true;
        $this->givenTheAccountsExist();
        $this->removeEveryScriptThisTestCouldHaveMade();
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->removeEveryScriptThisTestCouldHaveMade();

            // **The Sieve sessions go first, and the order is not cosmetic.**
            // Measured against Cyrus 3.10.2 (U-70 d): while a timsieved session
            // for a user is open, `DELETE user/x` over IMAP drops the *IMAP*
            // connection — the command takes effect, and the client is left
            // reading a socket the server has closed. Ending the session first
            // makes the delete ordinary.
            $this->sievePool?->closeAll();
            $this->sievePool = null;

            $this->removeTheAccounts();
        }

        $this->sievePool?->closeAll();
        $this->imapPool?->closeAll();
        $this->sievePool = null;
        $this->imapPool = null;
        $this->ready = false;
    }

    protected function emptyStore(): SieveStore
    {
        return new CyrusSieveStore($this->sievePool());
    }

    // -----------------------------------------------------------------
    // What only the real server can answer
    // -----------------------------------------------------------------

    /**
     * M3's open point 2, answered against the server rather than assumed: the
     * extensions the renderer declares in `require` are the ones timsieved
     * announces.
     */
    public function testTheServerAnnouncesTheExtensionsTheRendererNeeds(): void
    {
        $store = new CyrusSieveStore($this->sievePool());

        $announced = $store->sieveExtensions($this->anna());

        self::assertContains('vacation', $announced);
        self::assertContains('fileinto', $announced);
    }

    /**
     * M3's Definition of Done, first item: a rule set of vacation and redirect
     * becomes a script the server **accepts and activates**. The value of running
     * it here rather than against the double is that timsieved parses the script
     * before storing it — a renderer that produced almost-valid Sieve would pass
     * every unit test and fail here.
     */
    public function testARenderedRuleSetIsAcceptedAndActivated(): void
    {
        $installer = new SieveScriptInstaller(
            $this->emptyStore(),
            new SieveRenderer(),
            new CyrusSieveStore($this->sievePool()),
        );

        $script = $installer->install($this->anna(), $this->awkwardRules());

        self::assertNotNull($script);

        $active = $this->emptyStore()->activeScript($this->anna());

        self::assertNotNull($active);
        self::assertTrue($active->isManaged());
        self::assertSame($script->content, $active->content);
    }

    /**
     * The Definition of Done's second item, and the reason M3 exists: a script
     * the application did not write is **not** overwritten. Only an explicit
     * takeover changes that.
     */
    public function testAForeignScriptIsNotOverwrittenAndATakeoverIsExplicit(): void
    {
        $store = $this->emptyStore();
        $store->putScript($this->anna(), $this->foreignScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);

        $installer = new SieveScriptInstaller($store, new SieveRenderer());

        try {
            $installer->install($this->anna(), $this->rules());
            self::fail('A script the application did not write must not be overwritten.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_SCRIPT_FOREIGN, $failure->errorCode());
        }

        $untouched = $store->activeScript($this->anna());

        self::assertNotNull($untouched);
        self::assertTrue($untouched->isForeign(), 'the foreign script was replaced anyway');

        $installer->install($this->anna(), $this->rules(), takeOverForeignScript: true);

        $takenOver = $store->activeScript($this->anna());

        self::assertNotNull($takenOver);
        self::assertTrue($takenOver->isManaged());
    }

    /**
     * A script the server cannot parse is refused, and the refusal is a Sieve
     * failure rather than a protocol one — the distinction A3 needs in order to
     * tell a user "that rule is wrong" instead of "the server is unreachable".
     */
    public function testAScriptTheServerCannotParseIsRefused(): void
    {
        $store = $this->emptyStore();

        try {
            $store->putScript(
                $this->anna(),
                SieveScript::of(SieveScript::LEGACY_NAME, "keep\r\nthis is not sieve\r\n"),
            );
            self::fail('An invalid script must not be stored.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_REJECTED, $failure->errorCode());
        }

        self::assertSame([], $store->scriptNames($this->anna()));
    }

    /**
     * The pool's promise, against the real thing: one session for the whole
     * request, not one per operation.
     */
    public function testOneSessionServesEveryOperationOfOneAccount(): void
    {
        $store = $this->emptyStore();

        // `setUp()` already cleared both accounts, so the pool holds one session
        // per account before this case starts. What is asserted is that four
        // further operations on one account add none.
        $before = $this->sievePool()->size();

        $store->putScript($this->anna(), $this->managedScript());
        $store->activate($this->anna(), SieveScript::LEGACY_NAME);
        $store->scriptNames($this->anna());
        $store->activeScript($this->anna());

        self::assertSame(count($this->identities()), $before);
        self::assertSame(
            $before,
            $this->sievePool()->size(),
            'a session per operation is the legacy system\'s account list',
        );
    }

    // -----------------------------------------------------------------

    private function rules(): SieveRuleSet
    {
        return SieveRuleSet::of(vacation: VacationRule::of('Ich bin nicht da.'));
    }

    private function sievePool(): ManageSieveConnectionPool
    {
        return $this->sievePool ??= new ManageSieveConnectionPool(
            Endpoint::of(
                host: TestEnvironment::mailHost(),
                port: TestEnvironment::manageSievePort(),
                tlsMode: TlsMode::StartTls,
                caFile: TestEnvironment::caFile(),
            ),
            $this->credentials(),
            new StreamTransportFactory(),
            SieveTrace::disabled(),
        );
    }

    private function imapPool(): ConnectionPool
    {
        return $this->imapPool ??= new ConnectionPool(
            Endpoint::of(
                host: TestEnvironment::mailHost(),
                port: TestEnvironment::imapsPort(),
                caFile: TestEnvironment::caFile(),
            ),
            $this->credentials(),
            new StreamTransportFactory(),
            ProtocolTrace::disabled(),
        );
    }

    private function credentials(): Credentials
    {
        return Credentials::of(TestEnvironment::adminUser(), TestEnvironment::adminPassword());
    }

    /**
     * A Sieve space belongs to an account, so the accounts have to be there
     * before there is anything to write into.
     */
    private function givenTheAccountsExist(): void
    {
        $mailstore = new CyrusMailstore($this->imapPool());

        foreach ($this->identities() as $identity) {
            if (!$mailstore->mailboxExists($identity)) {
                $mailstore->createMailbox($identity);
            }
        }
    }

    private function removeTheAccounts(): void
    {
        $mailstore = new CyrusMailstore($this->imapPool());

        foreach ($this->identities() as $identity) {
            try {
                $mailstore->deleteMailbox($identity);
            } catch (MailstoreError) {
                // Nothing there, or nothing we may remove. A case below says so
                // more usefully than a teardown can.
            }
        }
    }

    /**
     * Exactly the names these cases can produce, and nothing else. Deleting by
     * pattern would be shorter and would eventually delete somebody's fixture.
     */
    private function removeEveryScriptThisTestCouldHaveMade(): void
    {
        $store = new CyrusSieveStore($this->sievePool());

        foreach ($this->identities() as $identity) {
            try {
                $store->deactivate($identity);

                foreach (self::SCRIPT_NAMES as $name) {
                    $store->deleteScript($identity, $name);
                }
            } catch (MailstoreError) {
                // The account may not exist yet on the first pass.
            }
        }
    }

    /**
     * @return list<MailboxIdentity>
     */
    private function identities(): array
    {
        return [$this->anna(), $this->bruno()];
    }

    /**
     * Certificate verification is on everywhere and there is no switch for it
     * (architecture 9.2), so this suite can only run where the name on the
     * certificate is the name we dial. Inside the environment it is; from the
     * host, where the ports are published on 127.0.0.1, it is not.
     */
    private function requireAVerifiableHostName(): void
    {
        $host = TestEnvironment::mailHost();

        if (filter_var($host, FILTER_VALIDATE_IP) === false && $host !== '') {
            return;
        }

        self::markTestSkipped(sprintf(
            'The test certificate names "%s" and this run dials "%s". Run the mailstore '
            . 'integration suite inside the environment: `bin/test-env test`.',
            TestEnvironment::CERTIFICATE_NAME,
            $host,
        ));
    }

    private function requireTheTestCertificateAuthority(): void
    {
        if (TestEnvironment::caFile() === null) {
            self::markTestSkipped(
                'The test CA is missing. Run `bin/test-env up`, which generates it.',
            );
        }
    }
}

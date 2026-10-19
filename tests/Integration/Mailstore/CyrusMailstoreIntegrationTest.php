<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Mailstore;

use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusMailstore;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\StreamTransportFactory;
use Mailadmin\Tests\Integration\TestEnvironment\RequiresTestEnvironment;
use Mailadmin\Tests\Integration\TestEnvironment\TestEnvironment;
use Mailadmin\Tests\Support\Mailstore\MailstoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The same contract as {@see \Mailadmin\Tests\Unit\Infrastructure\Mailstore\InMemoryMailstoreTest},
 * against a real Cyrus — which is what M2's "both implementations pass the same
 * set" means, and the only thing that makes the double trustworthy.
 *
 * **It works in a corner of the server, not in all of it.** F5's fixtures live
 * in `example.test` and `beispiel.invalid`; these tests use `leer.test`, the
 * domain the fixtures deliberately leave without accounts, and clean up after
 * themselves. Running the contract over the whole namespace would assert things
 * about F5 rather than about the adapter, and would delete other tests' data.
 *
 * `leer.test` is in Cyrus's `loginrealms`, which the `fqun` half of the contract
 * needs: without it Cyrus rejects the domain.
 */
#[CoversClass(CyrusMailstore::class)]
final class CyrusMailstoreIntegrationTest extends MailstoreContract
{
    use RequiresTestEnvironment;

    /** No fixture account lives here. See docker/db/fixtures/20-synthetic-data.sql. */
    private const string EMPTY_DOMAIN = 'leer.test';

    /** For the rename case, which needs a second domain. */
    private const string RENAME_DOMAIN = 'umzug.test';

    private ?ConnectionPool $pool = null;

    /**
     * Did `setUp()` get far enough for there to be anything to clean up?
     *
     * PHPUnit runs `tearDown()` even after a skip, and reaching for the server
     * there would turn every skip into an error on a machine that cannot talk to
     * it — the very failure {@see RequiresTestEnvironment} exists to prevent.
     */
    private bool $ready = false;

    protected function setUp(): void
    {
        $this->requireTestEnvironment();
        $this->requireAVerifiableHostName();

        $this->ready = true;
        $this->removeEverythingThisTestCouldHaveMade();
    }

    /**
     * Certificate verification is on everywhere and there is no switch for it
     * (architecture 9.2), so this suite can only run where the name on the
     * certificate is the name we dial. Inside the environment it is; from the
     * host, where the ports are published on 127.0.0.1, it is not.
     *
     * Skipping is the honest answer — the alternative would be an endpoint that
     * dials one name and verifies another, which is the beginning of exactly the
     * switch that must not exist.
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

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->removeEverythingThisTestCouldHaveMade();
        }

        $this->pool?->closeAll();
        $this->pool = null;
        $this->ready = false;
    }

    protected static function primaryDomain(): string
    {
        return self::EMPTY_DOMAIN;
    }

    protected static function secondaryDomain(): string
    {
        return self::RENAME_DOMAIN;
    }

    protected function emptyStore(): Mailstore
    {
        return new CyrusMailstore($this->pool());
    }

    // -----------------------------------------------------------------
    // O-8 and O-9 — the two assumptions F4 asked M2 to confirm
    // -----------------------------------------------------------------

    /**
     * **O-8, answered.** `MailboxIdentity::imapFolder()` assumes that under
     * `fqun` the domain stays at the end of the *whole* mailbox name, so a Sent
     * folder is `user/anna/Sent@leer.test` and not `user/anna@leer.test/Sent`.
     * That was derived from the Cyrus namespace rules and never checked.
     *
     * This is the check: create the folder through the identity, then ask the
     * server what it is called. If the assumption were wrong, the folder would
     * exist under a name nobody sees — which is the failure mode that makes it
     * worth a test of its own rather than a line in the contract.
     */
    public function testTheFqunFolderNameIsTheOneCyrusStores(): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', NamingScheme::Fqun);
        $store->createMailbox($anna);

        $store->createDefaultFolders($anna, DefaultFolders::of('Sent'));

        $expected = 'user/anna/Sent@' . self::EMPTY_DOMAIN;

        self::assertSame($expected, $anna->imapFolder('Sent'), 'the assumption itself');
        self::assertContains(
            $expected,
            $this->namesIn($store),
            'Cyrus stored the folder under a different name than the identity builds. '
            . 'O-8 is answered the other way and MailboxIdentity::imapFolder() is wrong.',
        );
    }

    /**
     * The `dotted` counterpart, for contrast: there the domain is part of the
     * user name and the folder simply hangs below it.
     */
    public function testTheDottedFolderNameIsTheOneCyrusStores(): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', NamingScheme::Dotted);
        $store->createMailbox($anna);

        $store->createDefaultFolders($anna, DefaultFolders::of('Sent'));

        $expected = 'user/anna.' . self::EMPTY_DOMAIN . '/Sent';

        self::assertSame($expected, $anna->imapFolder('Sent'));
        self::assertContains($expected, $this->namesIn($store));
    }

    /**
     * **O-9, as far as it can be answered from the outside.** The spool path is
     * diagnostic output — nothing in the application reads or writes the spool —
     * and IMAP does not expose it. What can be checked is the part the
     * asymmetry in architecture 3.3 is about: that the two schemes really do
     * produce two different *server* namespaces, and that a `dotted` account and
     * an `fqun` account with the same local part and domain are two mailboxes
     * rather than one.
     *
     * If they collided, the spool question would be moot and the naming-scheme
     * switch (E-14) would be a data-loss bug.
     */
    public function testTheTwoSchemesAreTwoDistinctMailboxes(): void
    {
        $store = $this->emptyStore();
        $dotted = self::identity('anna', NamingScheme::Dotted);
        $fqun = self::identity('anna', NamingScheme::Fqun);

        $store->createMailbox($dotted);
        $store->createMailbox($fqun);

        self::assertTrue($store->mailboxExists($dotted));
        self::assertTrue($store->mailboxExists($fqun));
        self::assertNotSame($dotted->imapMailbox(), $fqun->imapMailbox());

        $store->deleteMailbox($dotted);

        self::assertFalse($store->mailboxExists($dotted));
        self::assertTrue($store->mailboxExists($fqun), 'deleting one took the other with it');
    }

    /**
     * Architecture 9.3, against the real thing: the folder is created in the
     * user's name and no user password exists anywhere to do it with. The
     * fixtures' passwords are hashed, so if this needed one it could not work.
     */
    public function testFoldersAreCreatedForAUserWhosePasswordNobodyKnows(): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', NamingScheme::Dotted);
        $store->createMailbox($anna);

        $store->createDefaultFolders($anna, DefaultFolders::of('Sent', 'Drafts', 'Trash'));

        $names = $this->namesIn($store);

        foreach (['Sent', 'Drafts', 'Trash'] as $folder) {
            self::assertContains($anna->imapFolder($folder), $names);
        }
    }

    // -----------------------------------------------------------------

    private function pool(): ConnectionPool
    {
        if ($this->pool !== null) {
            return $this->pool;
        }

        $caFile = TestEnvironment::caFile();

        if ($caFile === null) {
            self::markTestSkipped(
                'The test CA is missing. Run `bin/test-env up`, which generates it.',
            );
        }

        return $this->pool = new ConnectionPool(
            Endpoint::of(
                host: TestEnvironment::mailHost(),
                port: TestEnvironment::imapsPort(),
                caFile: $caFile,
            ),
            Credentials::of(TestEnvironment::adminUser(), TestEnvironment::adminPassword()),
            new StreamTransportFactory(),
            ProtocolTrace::disabled(),
        );
    }

    /**
     * Cyrus is shared with the fixtures and outlives the test, so every run
     * starts and ends by removing exactly the names these cases can produce —
     * and nothing else. Deleting by pattern would be shorter and would
     * eventually delete somebody's fixture.
     */
    private function removeEverythingThisTestCouldHaveMade(): void
    {
        $store = new CyrusMailstore($this->pool());

        foreach ([self::EMPTY_DOMAIN, self::RENAME_DOMAIN] as $domain) {
            foreach ([NamingScheme::Dotted, NamingScheme::Fqun] as $scheme) {
                foreach (['anna', 'annabel', 'bruno', 'nobody', 'somebody', 'greta.mustermann'] as $local) {
                    try {
                        $store->deleteMailbox(self::identity($local, $scheme, $domain));
                    } catch (MailstoreError) {
                        // Nothing there, or nothing we may remove. Either way the
                        // test below will say so more usefully than a teardown can.
                    }
                }
            }
        }
    }
}

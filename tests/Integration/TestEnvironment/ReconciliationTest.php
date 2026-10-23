<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use Mailadmin\Application\Maintenance\Finding;
use Mailadmin\Application\Maintenance\FindingCategory;
use Mailadmin\Application\Maintenance\HealthComponent;
use Mailadmin\Application\Maintenance\HealthMonitor;
use Mailadmin\Application\Maintenance\HealthProbes;
use Mailadmin\Application\Maintenance\ProbeStatus;
use Mailadmin\Application\Maintenance\Reconcile;
use Mailadmin\Application\Maintenance\ReconciliationReport;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Infrastructure\Health\TimedProbe;
use Mailadmin\Infrastructure\Health\UncachedHealthReports;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusMailstore;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapCommand;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\StreamTransportFactory;
use Mailadmin\Infrastructure\Mailstore\Net\TlsMode;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveTrace;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\OpenConnection;
use Mailadmin\Infrastructure\Persistence\SqlMaintenanceInventory;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\FrozenClock;
use Mailadmin\Tests\Support\ListDeliveryFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A6's Definition of Done against F5: a drift made on purpose — a row without
 * a mailbox, a mailbox without a row, the other scheme's mailbox beside an
 * account, a dotted target nothing answers — is reported, and a deleted mailbox
 * is not. The health probes are run for real, once against the environment and
 * once against a port nothing listens on.
 *
 * Runs in `leer.test`, which holds no fixture accounts. The rest of F5 is
 * reconciled as well, so the assertions look for the findings this test made
 * rather than for an empty remainder. Everything it creates is removed by name.
 */
#[CoversClass(Reconcile::class)]
#[CoversClass(SqlMaintenanceInventory::class)]
#[CoversClass(TimedProbe::class)]
final class ReconciliationTest extends TestCase
{
    use RequiresTestEnvironment;

    private const string DOMAIN = 'leer.test';

    /** Local parts this test creates; cleaned up in both schemes. */
    private const array LOCALS = ['a6row', 'a6orphan', 'a6shadow', 'a6deleted'];

    private const string ALIAS = 'a6alias@leer.test';
    private const string DOTTED_TARGET = 'a6nobody.kunde.leer.test';

    private ?ConnectionPool $pool = null;
    private ?ManageSieveConnectionPool $sievePool = null;
    private ?Connection $connection = null;
    private bool $ready = false;

    protected function setUp(): void
    {
        $this->requireFixtures();

        if (filter_var(TestEnvironment::mailHost(), FILTER_VALIDATE_IP) !== false || TestEnvironment::caFile() === null) {
            self::markTestSkipped('Certificate verification needs the environment’s host name and CA: run `bin/test-env test`.');
        }

        $this->ready = true;
        $this->removeEverything();
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->removeEverything();
        }

        $this->pool?->closeAll();
        $this->sievePool?->closeAll();
        $this->pool = null;
        $this->sievePool = null;
        $this->ready = false;
    }

    public function testADeliberateDriftIsReportedAndNothingIsChanged(): void
    {
        $store = new CyrusMailstore($this->pool());

        // A row without a mailbox.
        $this->givenRow(self::identity('a6row'));

        // A mailbox without a row.
        $store->createMailbox(self::identity('a6orphan'));

        // An account, and its mailbox under the other scheme as well.
        $this->givenRow(self::identity('a6shadow'));
        $store->createMailbox(self::identity('a6shadow'));
        $store->createMailbox(self::identity('a6shadow', NamingScheme::Fqun));

        // A mailbox that was deleted again: not drift.
        $store->createMailbox(self::identity('a6deleted'));
        $store->deleteMailbox(self::identity('a6deleted'));

        // A dotted target that names no account — reported as written, never
        // split into a local part and a domain (A1's assumption on dest).
        $this->connection()->execute(
            'INSERT INTO virtual (alias, dest, username, status) VALUES (:alias, :dest, :username, 1)',
            ['alias' => self::ALIAS, 'dest' => self::DOTTED_TARGET, 'username' => self::DOMAIN],
        );

        $rowsBefore = $this->rowCount();

        $report = $this->reconcile();

        $missing = self::subjects($report, FindingCategory::AccountWithoutMailbox);
        self::assertContains('a6row.leer.test', $missing);
        self::assertNotContains('a6shadow.leer.test', $missing);

        $orphans = self::subjects($report, FindingCategory::MailboxWithoutAccount);
        self::assertContains('user/a6orphan.leer.test', $orphans);
        self::assertNotContains('user/a6shadow@leer.test', $orphans, 'A shadow is reported as a shadow, not twice.');
        self::assertEmpty(
            array_filter([...$orphans, ...$missing], static fn (string $subject): bool => str_contains($subject, 'a6deleted')),
            'A deleted mailbox is not drift.',
        );

        $shadows = $report->of(FindingCategory::ShadowMailbox);
        $shadow = self::findingFor($shadows, 'user/a6shadow@leer.test');
        self::assertSame('a6shadow.leer.test', $shadow->related);
        self::assertSame('fqun', $shadow->reason);

        $targets = $report->of(FindingCategory::VirtualTargetMissing);
        $target = self::findingFor($targets, self::ALIAS);
        self::assertSame(self::DOTTED_TARGET, $target->related);
        self::assertSame('no_account', $target->reason);
        self::assertSame(self::DOMAIN, $target->domain);

        // Report only: Cyrus and the tables are as they were.
        self::assertTrue($store->mailboxExists(self::identity('a6orphan')));
        self::assertTrue($store->mailboxExists(self::identity('a6shadow', NamingScheme::Fqun)));
        self::assertSame($rowsBefore, $this->rowCount());
    }

    public function testTheProbesPassAgainstTheEnvironment(): void
    {
        $report = $this->monitor($this->pool(), $this->sievePool())->fresh();

        self::assertSame(
            ['database' => 'ok', 'imap' => 'ok', 'managesieve' => 'ok'],
            self::statuses($report->checks),
            (string) json_encode($report->toDetailed()),
        );
        self::assertTrue($report->isHealthy());
    }

    public function testAClosedPortIsAnErrorNotACrash(): void
    {
        $closed = new ConnectionPool(
            Endpoint::of(host: TestEnvironment::mailHost(), port: 1, connectTimeout: 2.0, caFile: TestEnvironment::caFile()),
            Credentials::of(TestEnvironment::adminUser(), TestEnvironment::adminPassword()),
            new StreamTransportFactory(),
            ProtocolTrace::disabled(),
        );

        $report = $this->monitor($closed, $this->sievePool())->fresh();

        self::assertFalse($report->isHealthy());
        self::assertSame('degraded', $report->status());
        self::assertSame(
            ['database' => 'ok', 'imap' => 'error', 'managesieve' => 'ok'],
            self::statuses($report->checks),
        );
        self::assertNotNull($report->checks[1]->code);
        self::assertStringNotContainsString(TestEnvironment::adminPassword(), (string) json_encode($report->toDetailed()));
    }

    // -----------------------------------------------------------------

    private function reconcile(): ReconciliationReport
    {
        return (new Reconcile(
            new SqlMaintenanceInventory($this->connection()),
            new CyrusMailstore($this->pool()),
            new FrozenClock(),
            ListDeliveryFixture::delivery(),
        ))->handle(DomainFixture::superuser());
    }

    private function monitor(ConnectionPool $imap, ManageSieveConnectionPool $sieve): HealthMonitor
    {
        $connection = $this->connection();

        return new HealthMonitor(
            new HealthProbes(
                new TimedProbe(HealthComponent::Database, static function () use ($connection): void {
                    $connection->selectValue('SELECT 1');
                }),
                new TimedProbe(HealthComponent::Imap, static function () use ($imap): void {
                    $imap->administrative()->execute(ImapCommand::of('NOOP'));
                }),
                new TimedProbe(HealthComponent::ManageSieve, static function () use ($sieve): void {
                    $sieve->probe();
                }),
            ),
            new UncachedHealthReports(),
            new FrozenClock(),
        );
    }

    /**
     * @param list<\Mailadmin\Application\Maintenance\ProbeResult> $checks
     *
     * @return array<string, string>
     */
    private static function statuses(array $checks): array
    {
        $statuses = [];

        foreach ($checks as $check) {
            $statuses[$check->component->value] = $check->status->value;
        }

        self::assertNotContains(ProbeStatus::Skipped->value, $statuses);

        return $statuses;
    }

    /**
     * @return list<string>
     */
    private static function subjects(ReconciliationReport $report, FindingCategory $category): array
    {
        return array_map(static fn (Finding $finding): string => $finding->subject, $report->of($category));
    }

    /**
     * @param list<Finding> $findings
     */
    private static function findingFor(array $findings, string $subject): Finding
    {
        foreach ($findings as $finding) {
            if ($finding->subject === $subject) {
                return $finding;
            }
        }

        self::fail(sprintf('No finding for %s.', $subject));
    }

    private static function identity(string $local, NamingScheme $scheme = NamingScheme::Dotted): MailboxIdentity
    {
        return MailboxIdentity::create(LocalPart::fromString($local), DomainName::fromStoredValue(self::DOMAIN), $scheme);
    }

    private function givenRow(MailboxIdentity $identity): void
    {
        $this->connection()->execute(
            'INSERT INTO accountuser (username, password, prefix, domain_name) VALUES (:username, :password, :prefix, :domain)',
            [
                'username' => $identity->cyrusUsername(),
                // Not a hash of anything: this row never logs in.
                'password' => '!',
                'prefix' => self::DOMAIN,
                'domain' => self::DOMAIN,
            ],
        );
    }

    private function rowCount(): int
    {
        return (int) $this->connection()->selectValue('SELECT (SELECT COUNT(*) FROM accountuser) + (SELECT COUNT(*) FROM virtual)');
    }

    private function connection(): Connection
    {
        return $this->connection ??= new Connection(new OpenConnection(TestEnvironment::connection()));
    }

    private function pool(): ConnectionPool
    {
        return $this->pool ??= new ConnectionPool(
            Endpoint::of(host: TestEnvironment::mailHost(), port: TestEnvironment::imapsPort(), caFile: TestEnvironment::caFile()),
            Credentials::of(TestEnvironment::adminUser(), TestEnvironment::adminPassword()),
            new StreamTransportFactory(),
            ProtocolTrace::disabled(),
        );
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
            Credentials::of(TestEnvironment::adminUser(), TestEnvironment::adminPassword()),
            new StreamTransportFactory(),
            SieveTrace::disabled(),
        );
    }

    /**
     * Exactly the names this test can produce, in both tables and in Cyrus.
     */
    private function removeEverything(): void
    {
        $store = new CyrusMailstore($this->pool());

        $this->connection()->execute('DELETE FROM virtual WHERE alias = :alias', ['alias' => self::ALIAS]);

        foreach (self::LOCALS as $local) {
            foreach ([NamingScheme::Dotted, NamingScheme::Fqun] as $scheme) {
                $identity = self::identity($local, $scheme);

                $this->connection()->execute('DELETE FROM accountuser WHERE username = :u', ['u' => $identity->cyrusUsername()]);

                try {
                    $store->deleteMailbox($identity);
                } catch (MailstoreError) {
                    // Nothing there.
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Application\Account\AccountStore;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Application\MailingList\ListDeliveryMode;
use Mailadmin\Application\Maintenance\FindingCategory;
use Mailadmin\Application\Maintenance\HealthComponent;
use Mailadmin\Application\Maintenance\HealthProbes;
use Mailadmin\Application\Maintenance\HealthReportCache;
use Mailadmin\Application\Maintenance\ListRepairs;
use Mailadmin\Application\Maintenance\MaintenanceInventory;
use Mailadmin\Application\Maintenance\RepairErrorCode;
use Mailadmin\Application\Maintenance\RepairLedger;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Infrastructure\Health\TimedProbe;
use Mailadmin\Infrastructure\Health\UncachedHealthReports;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\InMemoryAccountStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Infrastructure\Persistence\RepairQueueRepository;
use Mailadmin\Infrastructure\Repair\InMemoryMaintenanceInventory;
use Mailadmin\Infrastructure\Repair\InMemoryRepairLedger;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;
use Mailadmin\Tests\Support\DomainFixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A6's endpoints through the container the application really boots, each
 * answer checked against the contract — and the allowlists and error codes
 * checked against the contract as well, because nothing else keeps the YAML
 * and the PHP one API.
 */
#[CoversNothing]
final class MaintenanceEndpointTest extends ContractTestCase
{
    private ContainerInterface $container;
    private InMemoryAuditTrail $trail;
    private InMemoryRepairLedger $ledger;
    private InMemoryMaintenanceInventory $inventory;
    private InMemoryMailstore $mailstore;
    private InMemoryAccountStore $accounts;
    private bool $imapDown = false;
    /** @var array<class-string, object> */
    private array $overrides = [];
    private InMemoryDomainStore $domains;

    protected function setUp(): void
    {
        self::routeTable();

        $directory = new InMemoryAdminDirectory();
        $directory->given('root', 'x', AdminRole::Superuser, []);
        $directory->given('master', 'x', AdminRole::DomainMaster, ['example.test']);

        $context = new AuditRequestContext();
        $this->trail = new InMemoryAuditTrail(context: $context);
        $this->ledger = new InMemoryRepairLedger();
        $this->inventory = (new InMemoryMaintenanceInventory())->givenDomain('example.test');
        $this->mailstore = new InMemoryMailstore();
        $this->accounts = new InMemoryAccountStore();
        $this->domains = new InMemoryDomainStore();
        $this->domains->given(DomainFixture::domain());

        $probes = new HealthProbes(
            new TimedProbe(HealthComponent::Database, static function (): void {
            }),
            new TimedProbe(HealthComponent::Imap, function (): void {
                if ($this->imapDown) {
                    throw new RuntimeException('connection refused');
                }
            }),
            new TimedProbe(HealthComponent::ManageSieve, static function (): void {
            }),
        );

        $this->overrides = [
            AdminDirectory::class => $directory,
            TokenDenylist::class => new InMemoryTokenDenylist(),
            AuditWriter::class => $this->trail,
            AuditRequestContext::class => $context,
            RepairLedger::class => $this->ledger,
            RepairQueue::class => $this->ledger,
            MaintenanceInventory::class => $this->inventory,
            Mailstore::class => $this->mailstore,
            AccountStore::class => $this->accounts,
            DomainStore::class => $this->domains,
            HealthProbes::class => $probes,
            HealthReportCache::class => new UncachedHealthReports(),
        ];
        $this->boot(ListDeliveryMode::Alias);
    }

    private function boot(ListDeliveryMode $mode): void
    {
        $environment = Bootstrap::prepare(Paths::detect());
        $environment->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($environment, $this->overrides + [
            ListDelivery::class => new ListDelivery(
                DomainName::fromString(ListDelivery::DEFAULT_TRANSPORT_DOMAIN),
                $mode,
            ),
        ]);
    }

    // ------------------------------------------------------ reconciliation

    public function testTheReconciliationReportsDriftInTheContractsShape(): void
    {
        $this->inventory
            ->givenAccount('anna.example.test', 'example.test')
            ->givenRow(new VirtualRow('info@example.test', 'info.kunde.example.test', 'example.test'));
        $this->mailstore->givenOrphan('user/ghost.example.test');

        $response = $this->call('GET', '/maintenance/reconciliation', 'root');

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/maintenance/reconciliation', $response);

        $body = self::bodyOf($response);
        self::assertSame(1, $body['summary']['account_without_mailbox']);
        self::assertSame(1, $body['summary']['mailbox_without_account']);
        self::assertSame(1, $body['summary']['virtual_target_missing']);
        self::assertSame('info.kunde.example.test', $body['findings'][2]['related']);
        self::assertSame([], $this->mailstore->operations(), 'Report only.');
    }

    public function testTheContractNamesEveryFindingCategory(): void
    {
        $declared = self::contract()['components']['schemas']['ReconciliationCategory']['enum'];
        $implemented = array_map(static fn (FindingCategory $category): string => $category->value, FindingCategory::cases());

        sort($declared);
        sort($implemented);

        self::assertSame($declared, $implemented);
    }

    // ---------------------------------------------------------- the queue

    public function testTheQueueIsListedNewestFirst(): void
    {
        $this->givenEntry('virtual.restore', [], 'address.delete');
        $this->givenEntry('mailstore.delete_mailbox', ['account' => 'anna.example.test', 'domain' => 'example.test']);

        $response = $this->call('GET', '/maintenance/repairs', 'root');

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/maintenance/repairs', $response);

        $body = self::bodyOf($response);
        self::assertSame([2, 1], array_column($body['items'], 'id'));
        self::assertTrue($body['items'][0]['retryable']);
        self::assertFalse($body['items'][1]['retryable']);
        self::assertSame(['account' => 'anna.example.test', 'domain' => 'example.test'], $body['items'][0]['parameters']);
    }

    public function testTheQueueCanBeFiltered(): void
    {
        $this->givenEntry('virtual.restore', [], 'address.delete');
        $this->givenEntry('mailstore.delete_mailbox', [], 'account.delete');

        $body = self::bodyOf($this->call('GET', '/maintenance/repairs?operation=account.&status=open', 'root'));

        self::assertSame(['account.delete'], array_column($body['items'], 'operation'));
    }

    public function testAnUnknownSortFieldIsRejectedAtTheBoundary(): void
    {
        $response = $this->call('GET', '/maintenance/repairs?sort=' . rawurlencode('id; DROP TABLE virtual'), 'root');

        self::assertSame(422, $response->getStatusCode());
    }

    public function testOneEntryIsShownAndAMissingOneIs404(): void
    {
        $this->givenEntry('sieve.restore', [], 'vacation.set');

        $found = $this->call('GET', '/maintenance/repairs/1', 'root');
        self::assertSame(200, $found->getStatusCode());
        $this->assertMatchesContract('get', '/maintenance/repairs/{repair}', $found);

        $missing = $this->call('GET', '/maintenance/repairs/9', 'root');
        self::assertSame(404, $missing->getStatusCode());
        $this->assertMatchesContract('get', '/maintenance/repairs/{repair}', $missing);

        // Not an id at all: the path pattern rejects it before any lookup.
        self::assertSame(422, $this->call('GET', '/maintenance/repairs/abc', 'root')->getStatusCode());
    }

    public function testARetryFinishesTheJobAndIsAudited(): void
    {
        $this->mailstore->givenOrphan('user/anna.example.test');
        $this->givenEntry('mailstore.delete_mailbox', ['account' => 'anna.example.test', 'domain' => 'example.test']);

        $response = $this->call('POST', '/maintenance/repairs/1/retry', 'root');

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('post', '/maintenance/repairs/{repair}/retry', $response);
        self::assertSame('resolved', self::bodyOf($response)['status']);
        self::assertSame('retried', self::bodyOf($response)['resolution']);
        self::assertSame('repair.retry', $this->trail->last()?->action);
        self::assertSame([], $this->mailstore->listMailboxes('user/%'));
    }

    public function testRefusedRetriesAnswer409WithAContractCode(): void
    {
        $this->givenEntry('virtual.restore', [], 'alias.delete');
        $this->accounts->given('anna.example.test', 'example.test');
        $this->givenEntry('mailstore.delete_mailbox', ['account' => 'anna.example.test', 'domain' => 'example.test']);

        $manual = $this->call('POST', '/maintenance/repairs/1/retry', 'root');
        self::assertSame(409, $manual->getStatusCode());
        $this->assertMatchesContract('post', '/maintenance/repairs/{repair}/retry', $manual);
        self::assertSame(RepairErrorCode::NOT_RETRYABLE, self::bodyOf($manual)['code']);

        $changed = $this->call('POST', '/maintenance/repairs/2/retry', 'root');
        self::assertSame(409, $changed->getStatusCode());
        $this->assertMatchesContract('post', '/maintenance/repairs/{repair}/retry', $changed);
        self::assertSame(RepairErrorCode::STATE_CHANGED, self::bodyOf($changed)['code']);

        self::assertSame(404, $this->call('POST', '/maintenance/repairs/7/retry', 'root')->getStatusCode());
    }

    public function testAnEntryIsDismissedWithANoteAndOnlyOnce(): void
    {
        $this->givenEntry('virtual.restore', [], 'alias.delete');

        $response = $this->call('POST', '/maintenance/repairs/1/resolve', 'root', ['note' => 'restored by hand']);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('post', '/maintenance/repairs/{repair}/resolve', $response);
        self::assertSame('dismissed', self::bodyOf($response)['resolution']);
        self::assertSame('restored by hand', self::bodyOf($response)['note']);
        self::assertSame('root', self::bodyOf($response)['resolved_by']);

        $again = $this->call('POST', '/maintenance/repairs/1/resolve', 'root', new \stdClass());
        self::assertSame(409, $again->getStatusCode());
        $this->assertMatchesContract('post', '/maintenance/repairs/{repair}/resolve', $again);
        self::assertSame(RepairErrorCode::ALREADY_RESOLVED, self::bodyOf($again)['code']);
    }

    public function testAnUnknownFieldInTheResolveBodyIsRejected(): void
    {
        $this->givenEntry('virtual.restore', [], 'alias.delete');

        $response = $this->call('POST', '/maintenance/repairs/1/resolve', 'root', ['note' => 'x', 'status' => 'open']);

        self::assertSame(422, $response->getStatusCode());
        self::assertTrue($this->ledger->find(1)?->isOpen());
    }

    // ------------------------------------------------------------ health

    public function testTheDetailedHealthNamesTheFailingComponent(): void
    {
        $this->imapDown = true;

        $response = $this->call('GET', '/maintenance/health', 'root');

        self::assertSame(200, $response->getStatusCode(), 'The details answer even when something is down.');
        $this->assertMatchesContract('get', '/maintenance/health', $response);

        $body = self::bodyOf($response);
        self::assertSame('degraded', $body['status']);
        self::assertSame(['database', 'imap', 'managesieve'], array_column($body['checks'], 'component'));
        self::assertSame('request.service_unavailable', $body['checks'][1]['code']);
        self::assertStringNotContainsString('refused', (string) json_encode($body));
    }

    public function testTheDetailedHealthShowsTheAliasDelivery(): void
    {
        $response = $this->call('GET', '/maintenance/health', 'root');

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/maintenance/health', $response);
        self::assertSame(
            ['mode' => 'alias', 'transport_domain' => 'maillist.invalid', 'transport_domain_is_managed' => false],
            self::bodyOf($response)['list_delivery'],
        );
    }

    public function testTheDetailedHealthShowsTheTransportDelivery(): void
    {
        $this->boot(ListDeliveryMode::Transport);

        $response = $this->call('GET', '/maintenance/health', 'root');

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/maintenance/health', $response);
        self::assertSame(
            ['mode' => 'transport', 'transport_domain' => 'maillist.invalid', 'transport_domain_is_managed' => false],
            self::bodyOf($response)['list_delivery'],
        );
    }

    public function testATransportDomainThatIsAlsoAManagedDomainIsReported(): void
    {
        // Only legacy data or a direct database edit gets here: create and rename refuse it.
        $this->domains->given(DomainFixture::domain('MailList.Invalid', prefix: 'maillist'));
        $this->boot(ListDeliveryMode::Transport);

        $response = $this->call('GET', '/maintenance/health', 'root');

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/maintenance/health', $response);
        self::assertTrue(self::bodyOf($response)['list_delivery']['transport_domain_is_managed']);
        self::assertSame('ok', self::bodyOf($response)['status'], 'A configuration warning, not a failed probe.');
    }

    public function testADatabaseThatCannotBeAskedLeavesTheManagedFlagOpen(): void
    {
        $this->domains->failOnExists(PersistenceError::configuration('no database configured'));

        $response = $this->call('GET', '/maintenance/health', 'root');

        self::assertSame(200, $response->getStatusCode(), 'The details answer even when the database does not.');
        $this->assertMatchesContract('get', '/maintenance/health', $response);
        self::assertNull(self::bodyOf($response)['list_delivery']['transport_domain_is_managed']);
    }

    // ------------------------------------------------------------ access

    /**
     * @return array<string, array{string, string, \stdClass|null}>
     */
    private static function everyOperation(): array
    {
        return [
            'reconciliation' => ['GET', '/maintenance/reconciliation', null],
            'list' => ['GET', '/maintenance/repairs', null],
            'get' => ['GET', '/maintenance/repairs/1', null],
            'retry' => ['POST', '/maintenance/repairs/1/retry', null],
            'resolve' => ['POST', '/maintenance/repairs/1/resolve', new \stdClass()],
            'health' => ['GET', '/maintenance/health', null],
        ];
    }

    public function testADomainMasterIsRefusedEverywhere(): void
    {
        $this->givenEntry('virtual.restore', [], 'alias.delete');

        foreach (self::everyOperation() as $name => [$method, $path, $body]) {
            $response = $this->call($method, $path, 'master', $body);

            self::assertSame(403, $response->getStatusCode(), $name);
            self::assertSame('request.forbidden', self::bodyOf($response)['code'], $name);
        }

        self::assertTrue($this->ledger->find(1)?->isOpen());
    }

    public function testWithoutATokenNothingIsReachable(): void
    {
        foreach (self::everyOperation() as $name => [$method, $path, $body]) {
            self::assertSame(401, $this->call($method, $path, null, $body)->getStatusCode(), $name);
        }
    }

    public function testThePermissionsAreSuperuserOnly(): void
    {
        foreach (['GET /maintenance/reconciliation', 'GET /maintenance/repairs', 'GET /maintenance/repairs/{repair}', 'GET /maintenance/health'] as $operation) {
            self::assertSame(Permission::MaintenanceRead->value, self::operations()[$operation]['x-permission'], $operation);
        }

        foreach (['POST /maintenance/repairs/{repair}/retry', 'POST /maintenance/repairs/{repair}/resolve'] as $operation) {
            self::assertSame(Permission::MaintenanceWrite->value, self::operations()[$operation]['x-permission'], $operation);
        }

        self::assertFalse(RolePermissions::domainMaster()->includes(Permission::MaintenanceRead));
        self::assertFalse(RolePermissions::domainMaster()->includes(Permission::MaintenanceWrite));
    }

    // -------------------------------------- the contract and the PHP agree

    public function testEveryRepairErrorCodeIsInTheContract(): void
    {
        $declared = self::contract()['components']['schemas']['ErrorCode']['enum'];

        foreach (RepairErrorCode::all() as $code) {
            self::assertContains($code, $declared);
        }
    }

    public function testTheContractOffersExactlyTheSortFieldsTheRepositoryImplements(): void
    {
        $declared = self::contract()['components']['schemas']['RepairSort']['enum'];
        $implemented = self::repositoryKeys('sortable');

        sort($declared);
        sort($implemented);

        self::assertSame($declared, $implemented);
    }

    public function testTheContractOffersExactlyTheFiltersTheRepositoryImplements(): void
    {
        $shared = self::contract()['components']['parameters'];
        $declared = [];

        foreach (self::operations()['GET /maintenance/repairs']['parameters'] as $parameter) {
            $pointer = $parameter['$ref'] ?? null;

            if (is_string($pointer)) {
                $parameter = $shared[substr($pointer, (int) strrpos($pointer, '/') + 1)];
            }

            if (!in_array($parameter['name'], ['page', 'per_page', 'sort', 'order'], true)) {
                $declared[] = $parameter['name'];
            }

            if ($parameter['name'] === 'order') {
                self::assertSame(SortDirection::Descending->value, $parameter['schema']['default']);
            }
        }

        $implemented = self::repositoryKeys('filterable');

        sort($declared);
        sort($implemented);

        self::assertSame($declared, $implemented);
        self::assertSame(SortDirection::Descending, ListRepairs::defaultOrder());
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $parameters
     */
    private function givenEntry(string $step, array $parameters, string $operation = 'account.delete'): void
    {
        $this->ledger->record(new RepairRequest(
            $operation,
            $step,
            'user/anna.example.test',
            'intended',
            'reached',
            'test',
            $parameters,
        ));
    }

    /**
     * @return list<string>
     */
    private static function repositoryKeys(string $method): array
    {
        $class = new \ReflectionClass(RepairQueueRepository::class);
        $allowlist = $class->getMethod($method)->invoke($class->newInstanceWithoutConstructor());

        self::assertTrue($allowlist instanceof SortAllowlist || $allowlist instanceof FilterAllowlist);

        return $allowlist->keys();
    }

    private function assertMatchesContract(string $method, string $path, ResponseInterface $response): void
    {
        (new ValidatorBuilder())
            ->fromYamlFile(self::projectRoot() . '/var/cache/openapi.bundled.yaml')
            ->getResponseValidator()
            ->validate(new OperationAddress($path, $method), $response);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed>|\stdClass|null $body
     */
    private function call(string $method, string $path, ?string $username, array|\stdClass|null $body = null): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'https://mail.example.test/api/v1' . $path);

        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        if ($username !== null) {
            $issuer = $this->container->get(TokenIssuer::class);
            self::assertInstanceOf(TokenIssuer::class, $issuer);
            $role = $username === 'root' ? AdminRole::Superuser : AdminRole::DomainMaster;
            $request = $request->withHeader('Authorization', 'Bearer ' . $issuer->issue($username, $role)->value);
        }

        $chain = $this->container->get(MiddlewareDispatcher::class);
        self::assertInstanceOf(MiddlewareDispatcher::class, $chain);

        return $chain->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodyOf(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

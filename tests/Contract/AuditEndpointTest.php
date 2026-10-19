<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditReader;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Audit\ListAuditTrail;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Infrastructure\Persistence\AuditRepository;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * `GET /audit`, through the container the application really boots.
 *
 * Two kinds of assertion live here, and both are about the seam between the YAML
 * and the PHP rather than about either on its own:
 *
 *  - **The endpoint behaves as the contract says**, including the `403` a domain
 *    master gets — the first operation in this application that any role is
 *    actually refused, and therefore the first real exercise of S3's split.
 *  - **The allowlists and the contract describe the same API.** A `sort` value
 *    the contract offers and {@see AuditRepository} does not would be a `500` in
 *    front of somebody; the reverse would be a capability nobody can discover.
 *    They are two artefacts and nothing but this file keeps them one.
 */
#[CoversNothing]
final class AuditEndpointTest extends ContractTestCase
{
    private ContainerInterface $container;
    private InMemoryAuditTrail $trail;
    private AuditRequestContext $context;

    protected function setUp(): void
    {
        self::routeTable();

        $directory = new InMemoryAdminDirectory();
        $directory->given('root', 'x', AdminRole::Superuser, []);
        $directory->given('master', 'x', AdminRole::DomainMaster, ['own.example']);

        $this->context = new AuditRequestContext();
        $this->trail = new InMemoryAuditTrail(context: $this->context);

        $environment = Bootstrap::prepare(Paths::detect());
        $environment->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($environment, [
            AdminDirectory::class => $directory,
            TokenDenylist::class => new InMemoryTokenDenylist(),
            AuditWriter::class => $this->trail,
            AuditReader::class => $this->trail,
            AuditRequestContext::class => $this->context,
        ]);
    }

    public function testASuperuserGetsThePageTheContractDescribes(): void
    {
        $this->trail->record(AuditEntry::creation(
            ActorContext::superuser('root'),
            'domain.create',
            'domain',
            'example.test',
            after: ['name' => 'example.test', 'max_accounts' => 20],
            domain: 'example.test',
        ));

        $response = $this->get('/api/v1/audit', $this->tokenFor('root', AdminRole::Superuser));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract($response);

        $body = self::bodyOf($response);

        self::assertSame(1, $body['meta']['total']);
        self::assertSame('domain.create', $body['items'][0]['action']);
        self::assertSame('succeeded', $body['items'][0]['outcome']);
        self::assertNull($body['items'][0]['before']);
    }

    /**
     * `audit.read` is superuser-only (U-43): an entry names administrators
     * across domain boundaries.
     */
    public function testADomainMasterIsRefused(): void
    {
        $response = $this->get('/api/v1/audit', $this->tokenFor('master', AdminRole::DomainMaster));

        self::assertSame(403, $response->getStatusCode());
        $this->assertMatchesContract($response);
        self::assertSame('request.forbidden', self::bodyOf($response)['code']);
    }

    /**
     * And the refusal is itself recorded — the loop S4 closes: an attempt to
     * read the trail without the right to is in the trail.
     */
    public function testTheRefusalIsRecordedWithTheRequestIdThatProducedIt(): void
    {
        $this->get('/api/v1/audit', $this->tokenFor('master', AdminRole::DomainMaster));

        $entry = $this->trail->last();

        self::assertNotNull($entry);
        self::assertSame('master', $entry->actor);
        self::assertSame('audit.read', $entry->action);
        self::assertSame('denied', $entry->outcome->value);

        // The join to the application log (S4's definition of done). It is bound
        // by the middleware and read by the writer; a value here proves the two
        // saw the same context object.
        self::assertNotSame('', $entry->requestId);
    }

    public function testWithoutATokenItIsNotReachable(): void
    {
        $response = $this->get('/api/v1/audit', null);

        self::assertSame(401, $response->getStatusCode());
        $this->assertMatchesContract($response);
    }

    public function testAnUnknownSortFieldIsRejectedAtTheBoundaryRatherThanInSql(): void
    {
        $response = $this->get(
            '/api/v1/audit?sort=' . rawurlencode('id; DROP TABLE domain'),
            $this->tokenFor('root', AdminRole::Superuser),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('request.validation_failed', self::bodyOf($response)['code']);
    }

    public function testThePageSizeCeilingIsEnforcedByTheSchema(): void
    {
        $response = $this->get('/api/v1/audit?per_page=500', $this->tokenFor('root', AdminRole::Superuser));

        self::assertSame(422, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // The contract and the repository have to describe the same thing
    // -----------------------------------------------------------------

    public function testTheContractOffersExactlyTheSortFieldsTheRepositoryImplements(): void
    {
        $declared = self::contract()['components']['schemas']['AuditSort']['enum'];
        $implemented = self::repositoryKeys('sortable');

        sort($declared);
        sort($implemented);

        self::assertSame($declared, $implemented);
    }

    public function testTheContractOffersExactlyTheFiltersTheRepositoryImplements(): void
    {
        $declared = [];

        foreach (self::declaredParameters() as $parameter) {
            $name = $parameter['name'] ?? null;

            if (is_string($name) && !in_array($name, ['page', 'per_page', 'sort', 'order'], true)) {
                $declared[] = $name;
            }
        }

        $implemented = self::repositoryKeys('filterable');

        sort($declared);
        sort($implemented);

        self::assertSame($declared, $implemented);
    }

    /**
     * The contract documents `desc`; an OpenAPI default is documentation and
     * never reaches the parser, so the handler applies it. If the two drift, a
     * client reading the contract gets the opposite of what it asked for.
     */
    public function testTheDocumentedDefaultOrderIsTheOneTheCodeApplies(): void
    {
        $declared = null;

        foreach (self::declaredParameters() as $parameter) {
            if (($parameter['name'] ?? null) === 'order') {
                /** @var mixed $declared */
                $declared = $parameter['schema']['default'] ?? null;
            }
        }

        self::assertSame(SortDirection::Descending->value, $declared);
        self::assertSame(SortDirection::Descending, ListAuditTrail::defaultOrder());
    }

    public function testTheOperationDeclaresTheForbiddenResponseItCanProduce(): void
    {
        // Belt and braces beside PermissionContractTest, which enforces the rule
        // for every operation: this one names the operation, so a failure says
        // which contract file to open.
        self::assertArrayHasKey(403, self::operation()['responses']);
        self::assertSame(Permission::AuditRead->value, self::operation()['x-permission']);
        self::assertFalse(RolePermissions::domainMaster()->includes(Permission::AuditRead));
    }

    // -----------------------------------------------------------------

    /**
     * The operation's parameters with the `$ref`s followed.
     *
     * The bundler merges the partials into one document but leaves internal
     * pointers as pointers — that is the whole point of an internal `$ref`. A
     * test comparing parameter names therefore has to resolve them, or it
     * compares an empty list against an empty list and passes for ever.
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredParameters(): array
    {
        /** @var array<string, mixed> $shared */
        $shared = self::contract()['components']['parameters'];

        $resolved = [];

        /** @var mixed $parameter */
        foreach ((array) (self::operation()['parameters'] ?? []) as $parameter) {
            self::assertIsArray($parameter);

            $pointer = $parameter['$ref'] ?? null;

            if (is_string($pointer)) {
                $name = substr($pointer, (int) strrpos($pointer, '/') + 1);

                self::assertArrayHasKey($name, $shared, $pointer . ' points at nothing.');

                /** @var array<string, mixed> $parameter */
                $parameter = $shared[$name];
            }

            /** @var array<string, mixed> $parameter */
            $resolved[] = $parameter;
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private static function operation(): array
    {
        /** @var array<string, mixed> $operation */
        $operation = self::operations()['GET /audit'];

        return $operation;
    }

    /**
     * The keys of one of the repository's allowlists.
     *
     * They are protected, deliberately — a repository declares what it sorts and
     * filters by, it does not expose it as an API. Reflection is the honest way
     * to read them from a test rather than widening them for one.
     *
     * @return list<string>
     */
    private static function repositoryKeys(string $method): array
    {
        $class = new \ReflectionClass(AuditRepository::class);
        $allowlist = $class->getMethod($method)->invoke($class->newInstanceWithoutConstructor());

        self::assertTrue(
            $allowlist instanceof SortAllowlist || $allowlist instanceof FilterAllowlist,
            $method . '() no longer returns an allowlist.',
        );

        return $allowlist->keys();
    }

    private function assertMatchesContract(ResponseInterface $response): void
    {
        (new ValidatorBuilder())
            ->fromYamlFile(self::projectRoot() . '/var/cache/openapi.bundled.yaml')
            ->getResponseValidator()
            ->validate(new OperationAddress('/audit', 'get'), $response);

        $this->addToAssertionCount(1);
    }

    private function tokenFor(string $username, AdminRole $role): string
    {
        $issuer = $this->container->get(TokenIssuer::class);

        self::assertInstanceOf(TokenIssuer::class, $issuer);

        return $issuer->issue($username, $role)->value;
    }

    private function get(string $path, ?string $accessToken): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://mail.example.test' . $path);

        // Nyholm's factory fills the URI but not `getQueryParams()`; the real
        // front controller populates it from `$_GET` (see ServerRequestFactory).
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);

        if ($accessToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $accessToken);
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

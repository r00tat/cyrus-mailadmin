<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Persistence\DomainRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Infrastructure\Security\LoginAttemptLog;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;
use Mailadmin\Tests\Support\DomainFixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The five domain endpoints, driven through the container the application really
 * boots, with every answer checked against the contract.
 *
 * Only what would otherwise reach MySQL or Cyrus is doubled. The middleware
 * chain, the dispatcher, the route table compiled from `openapi/`, the token
 * issuer and — the point of this file — the **authorisation middleware** are the
 * real ones. That is what makes "a domain master gets `403` from `POST /domains`
 * and `404` from a foreign domain" an assertion about the application rather
 * than about a policy object in isolation.
 */
#[CoversNothing]
final class DomainEndpointTest extends ContractTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;
    private InMemoryDomainStore $store;
    private InMemoryAuditTrail $trail;

    protected function setUp(): void
    {
        self::routeTable();

        $hasher = new PasswordHasher(PasswordHashingOptions::of(
            bcryptCost: 4,
            argon2MemoryKib: 8192,
            argon2TimeCost: 1,
            argon2Threads: 1,
        ));
        $hash = $hasher->hash(Password::alreadyInUse(self::PASSWORD, PasswordSubject::AdminUser));

        $directory = new InMemoryAdminDirectory();
        $directory->given('root', $hash, AdminRole::Superuser, []);
        $directory->given('anna', $hash, AdminRole::DomainMaster, ['example.test']);

        $this->store = new InMemoryDomainStore();
        $this->store->given(DomainFixture::domain('example.test'), 'anna.example.test');
        $this->store->given(DomainFixture::domain('fremd.test', prefix: 'fremd.test'));

        $auditContext = new AuditRequestContext();
        $this->trail = new InMemoryAuditTrail(context: $auditContext);

        $context = Bootstrap::prepare(Paths::detect());
        $context->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($context, [
            AdminDirectory::class => $directory,
            RefreshTokenStore::class => new InMemoryRefreshTokenStore(),
            TokenDenylist::class => new InMemoryTokenDenylist(),
            LoginAttemptLog::class => new InMemoryLoginAttemptLog(),
            DomainStore::class => $this->store,
            Mailstore::class => new InMemoryMailstore(),
            RepairQueue::class => new InMemoryRepairQueue(),
            AuditWriter::class => $this->trail,
            AuditRequestContext::class => $auditContext,
        ]);
    }

    public function testASuperuserSeesEveryDomainInTheContractsShape(): void
    {
        $response = $this->get('/api/v1/domains', $this->tokenFor('root'));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/domains', $response);

        $body = self::bodyOf($response);

        self::assertSame(2, $body['meta']['total']);
        self::assertSame(['example.test', 'fremd.test'], array_column($body['items'], 'name'));
    }

    public function testADomainMasterSeesOnlyTheirOwn(): void
    {
        $response = $this->get('/api/v1/domains', $this->tokenFor('anna'));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/domains', $response);
        self::assertSame(['example.test'], array_column(self::bodyOf($response)['items'], 'name'));
    }

    public function testAnUndeclaredQueryParameterIsReportedRatherThanIgnored(): void
    {
        $response = $this->get('/api/v1/domains?prefix=example', $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('get', '/domains', $response);
        self::assertSame('request.validation_failed', self::bodyOf($response)['code']);
    }

    public function testReadingOneDomainCarriesAnEtag(): void
    {
        $response = $this->get('/api/v1/domains/example.test', $this->tokenFor('anna'));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/domains/{domain}', $response);
        self::assertStringStartsWith('W/"', $response->getHeaderLine('ETag'));
        self::assertSame('example.test', self::bodyOf($response)['name']);
    }

    /**
     * The `403`/`404` split, over the wire, through the real middleware.
     */
    public function testAForeignDomainAnswersExactlyLikeAMissingOne(): void
    {
        $token = $this->tokenFor('anna');

        $foreign = $this->get('/api/v1/domains/fremd.test', $token);
        $missing = $this->get('/api/v1/domains/gibtsnicht.test', $token);

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame(404, $missing->getStatusCode());
        $this->assertMatchesContract('get', '/domains/{domain}', $foreign);

        // Byte for byte apart from the path and the request id, which differ
        // between any two requests. A single other difference would be the
        // signal that tells "someone else's" from "nobody's" (U-44).
        self::assertSame(
            self::withoutPerRequestFields(self::bodyOf($foreign)),
            self::withoutPerRequestFields(self::bodyOf($missing)),
        );
    }

    public function testADomainMasterMayNotCreateADomain(): void
    {
        // A1's Definition of Done, and the reason the operation has to declare a
        // 403 in the contract at all (openapi/README.md, 5).
        $response = $this->post('/api/v1/domains', ['name' => 'neu.test'], $this->tokenFor('anna'));

        self::assertSame(403, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains', $response);
        self::assertSame('request.forbidden', self::bodyOf($response)['code']);
    }

    public function testCreatingADomainAnswersWithTheExternalStepsStillOutstanding(): void
    {
        $response = $this->post(
            '/api/v1/domains',
            ['name' => 'neu.test', 'naming_scheme' => 'fqun'],
            $this->tokenFor('root'),
        );

        self::assertSame(201, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains', $response);
        self::assertSame('/api/v1/domains/neu.test', $response->getHeaderLine('Location'));

        $body = self::bodyOf($response);

        self::assertSame('neu.test', $body['domain']['name']);
        self::assertSame('fqun', $body['domain']['naming_scheme']);
        // `fqun` needs the domain in the Cyrus `loginrealms`, which Salt owns
        // and we cannot check (E-9). The interface says so; it does not verify.
        self::assertContains('cyrus_loginrealm', $body['next_steps']);
        self::assertContains('dns_mx', $body['next_steps']);

        // The use case's entry, through the real wiring, carrying the request
        // id the middleware bound (U-64).
        $entry = $this->trail->last();

        self::assertNotNull($entry);
        self::assertSame('domain.create', $entry->action);
        self::assertSame(AuditOutcome::Succeeded, $entry->outcome);
        self::assertSame('neu.test', $entry->after['name'] ?? null);
        self::assertNotSame('', $entry->requestId);
    }

    public function testAnExistingNameIsAConflictInTheContractsShape(): void
    {
        $response = $this->post('/api/v1/domains', ['name' => 'example.test'], $this->tokenFor('root'));

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains', $response);
        self::assertSame('domain.name_taken', self::bodyOf($response)['code']);
    }

    public function testAnUnconventionalNameIsRejectedWithTheModelsOwnCode(): void
    {
        $response = $this->post('/api/v1/domains', ['name' => 'not_a_domain'], $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains', $response);
        self::assertSame('domain.invalid_name', self::bodyOf($response)['code']);
    }

    public function testTheListTransportDomainIsRefusedOnTheNameField(): void
    {
        $response = $this->post('/api/v1/domains', ['name' => 'maillist.invalid'], $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains', $response);
        self::assertSame('domain.invalid_name', self::bodyOf($response)['code']);
        self::assertSame(['body.name' => 'domain.invalid_name'], self::bodyOf($response)['errors']);
    }

    public function testARenameToTheListTransportDomainIsRefusedOnTheNameField(): void
    {
        $token = $this->tokenFor('root');
        $etag = $this->get('/api/v1/domains/fremd.test', $token)->getHeaderLine('ETag');

        $response = $this->patch(
            '/api/v1/domains/fremd.test',
            ['name' => 'maillist.invalid', 'confirm_rename' => true],
            $token,
            $etag,
        );

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('patch', '/domains/{domain}', $response);
        self::assertSame('domain.invalid_name', self::bodyOf($response)['code']);
        self::assertSame(['body.name' => 'domain.invalid_name'], self::bodyOf($response)['errors']);
    }

    public function testAFieldTheContractDoesNotKnowIsRejectedAtTheBoundary(): void
    {
        // `additionalProperties: false`. The prefix is derived, never accepted
        // (U-46), and this is the boundary that says so.
        $response = $this->post(
            '/api/v1/domains',
            ['name' => 'neu.test', 'legacy_prefix' => 'stolen'],
            $this->tokenFor('root'),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('request.validation_failed', self::bodyOf($response)['code']);
    }

    public function testPatchingWithoutAnIfMatchIsFourTwentyEight(): void
    {
        $response = $this->patch('/api/v1/domains/example.test', ['max_accounts' => 5], $this->tokenFor('root'));

        self::assertSame(428, $response->getStatusCode());
        $this->assertMatchesContract('patch', '/domains/{domain}', $response);
        self::assertSame('request.precondition_required', self::bodyOf($response)['code']);
    }

    public function testPatchingWithTheEtagFromTheLastReadSucceeds(): void
    {
        $token = $this->tokenFor('root');
        $etag = $this->get('/api/v1/domains/example.test', $token)->getHeaderLine('ETag');

        $response = $this->patch(
            '/api/v1/domains/example.test',
            ['max_accounts' => 5],
            $token,
            $etag,
        );

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('patch', '/domains/{domain}', $response);
        self::assertSame(5, self::bodyOf($response)['max_accounts']);
    }

    public function testARenameWithoutConfirmationIsRefused(): void
    {
        $token = $this->tokenFor('root');
        $etag = $this->get('/api/v1/domains/example.test', $token)->getHeaderLine('ETag');

        $response = $this->patch('/api/v1/domains/example.test', ['name' => 'neu.test'], $token, $etag);

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('patch', '/domains/{domain}', $response);
        self::assertSame('domain.rename_not_confirmed', self::bodyOf($response)['code']);
    }

    public function testAConfirmedRenamePointsAtTheNewLocation(): void
    {
        $token = $this->tokenFor('root');
        $etag = $this->get('/api/v1/domains/fremd.test', $token)->getHeaderLine('ETag');

        $response = $this->patch(
            '/api/v1/domains/fremd.test',
            ['name' => 'neu.test', 'confirm_rename' => true],
            $token,
            $etag,
        );

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('patch', '/domains/{domain}', $response);
        self::assertSame('/api/v1/domains/neu.test', $response->getHeaderLine('Location'));
        self::assertSame('neu.test', self::bodyOf($response)['name']);
    }

    public function testDeletingADomainWithAccountsIsRefused(): void
    {
        $token = $this->tokenFor('root');
        $etag = $this->get('/api/v1/domains/example.test', $token)->getHeaderLine('ETag');

        $response = $this->delete('/api/v1/domains/example.test', $token, $etag);

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('delete', '/domains/{domain}', $response);
        self::assertSame('domain.not_empty', self::bodyOf($response)['code']);
    }

    public function testDeletingADomainWithAddressesIsRefused(): void
    {
        $this->store->givenAlias('@fremd.test');
        $token = $this->tokenFor('root');
        $etag = $this->get('/api/v1/domains/fremd.test', $token)->getHeaderLine('ETag');

        $response = $this->delete('/api/v1/domains/fremd.test', $token, $etag);

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('delete', '/domains/{domain}', $response);
        self::assertSame('domain.still_has_addresses', self::bodyOf($response)['code']);
    }

    public function testASubdomainThatWouldMakeAUserNameAmbiguousIsAConflict(): void
    {
        $this->store->given(DomainFixture::domain('example.test'), 'anna.example.test', 'info.kunde.example.test');

        $response = $this->post('/api/v1/domains', ['name' => 'kunde.example.test'], $this->tokenFor('root'));

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains', $response);
        self::assertSame('domain.namespace_conflict', self::bodyOf($response)['code']);
    }

    public function testDeletingAnEmptyDomainAnswersWithNoContent(): void
    {
        $token = $this->tokenFor('root');
        $etag = $this->get('/api/v1/domains/fremd.test', $token)->getHeaderLine('ETag');

        $response = $this->delete('/api/v1/domains/fremd.test', $token, $etag);

        self::assertSame(204, $response->getStatusCode());
        $this->assertMatchesContract('delete', '/domains/{domain}', $response);
        self::assertSame('', (string) $response->getBody());
        self::assertSame('domain.delete', $this->trail->last()?->action);
    }

    public function testADomainMasterMayNotDelete(): void
    {
        $response = $this->delete('/api/v1/domains/example.test', $this->tokenFor('anna'), '*');

        self::assertSame(403, $response->getStatusCode());
        $this->assertMatchesContract('delete', '/domains/{domain}', $response);
    }

    public function testNoneOfTheseEndpointsIsReachableWithoutAToken(): void
    {
        self::assertSame(401, $this->get('/api/v1/domains', null)->getStatusCode());
        self::assertSame(401, $this->get('/api/v1/domains/example.test', null)->getStatusCode());
        self::assertSame(401, $this->post('/api/v1/domains', ['name' => 'neu.test'], null)->getStatusCode());
    }

    public function testTheContractOffersExactlyTheSortFieldsTheRepositoryImplements(): void
    {
        // Two artefacts, one truth. A `sort` value in the contract that the
        // repository does not know is a `422` nobody expected; one the
        // repository knows and the contract does not is unreachable.
        /** @var array<string, mixed> $parameter */
        $parameter = self::contract()['components']['parameters']['DomainSort'];

        self::assertSame(
            DomainRepository::sortAllowlist()->keys(),
            $parameter['schema']['enum'],
        );
        self::assertSame(
            DomainRepository::sortAllowlist()->defaultKey(),
            $parameter['schema']['default'],
        );
    }

    // -----------------------------------------------------------------

    private function assertMatchesContract(string $method, string $path, ResponseInterface $response): void
    {
        (new ValidatorBuilder())
            ->fromYamlFile(self::projectRoot() . '/var/cache/openapi.bundled.yaml')
            ->getResponseValidator()
            ->validate(new OperationAddress($path, $method), $response);

        $this->addToAssertionCount(1);
    }

    private function tokenFor(string $username): string
    {
        $response = $this->post('/api/v1/auth/token', [
            'username' => $username,
            'password' => self::PASSWORD,
        ], null);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) self::bodyOf($response)['access_token'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function post(string $path, ?array $body, ?string $token): ResponseInterface
    {
        return $this->send('POST', $path, $body, $token, null);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $path, array $body, ?string $token, ?string $ifMatch = null): ResponseInterface
    {
        return $this->send('PATCH', $path, $body, $token, $ifMatch);
    }

    private function delete(string $path, ?string $token, ?string $ifMatch = null): ResponseInterface
    {
        return $this->send('DELETE', $path, null, $token, $ifMatch);
    }

    private function get(string $path, ?string $token): ResponseInterface
    {
        return $this->send('GET', $path, null, $token, null);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(
        string $method,
        string $path,
        ?array $body,
        ?string $token,
        ?string $ifMatch,
    ): ResponseInterface {
        $factory = new Psr17Factory();
        $uri = $factory->createUri('https://mail.example.test' . $path);
        $request = $factory->createServerRequest($method, $uri);

        parse_str($uri->getQuery(), $query);
        $request = $request->withQueryParams($query);

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        if ($ifMatch !== null) {
            $request = $request->withHeader('If-Match', $ifMatch);
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
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function withoutPerRequestFields(array $document): array
    {
        unset($document['instance'], $document['request_id']);

        return $document;
    }
}

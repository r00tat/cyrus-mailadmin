<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Application\Account\AccountErrorCode;
use Mailadmin\Application\Account\AccountStore;
use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Password\CommonPasswordList;
use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Persistence\AccountRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryAccountStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Persistence\InMemoryVirtualStore;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The eight account endpoints, driven through the container the application
 * really boots, with every answer checked against the contract.
 *
 * Only what would reach MySQL or Cyrus is doubled. The authorisation
 * middleware is the real one, which is what makes "a foreign account answers
 * exactly like a missing one" an assertion about the application.
 *
 * Both roles hold every `account.*` permission, so no endpoint here has a
 * `403`: the domain master's limit is the domain, and that is a `404`.
 */
#[CoversNothing]
final class AccountEndpointTest extends ContractTestCase
{
    private const string ADMIN_PASSWORD = 'correct-horse-battery-staple';
    private const string ACCOUNT_PASSWORD = 'Korrekt-Pferd-Batterie-42';

    private ContainerInterface $container;

    private InMemoryVirtualStore $virtual;
    private InMemoryAccountStore $accounts;
    private InMemoryMailstore $mailstore;
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
        $hash = $hasher->hash(Password::alreadyInUse(self::ADMIN_PASSWORD, PasswordSubject::AdminUser));

        $directory = new InMemoryAdminDirectory();
        $directory->given('root', $hash, AdminRole::Superuser, []);
        $directory->given('anna', $hash, AdminRole::DomainMaster, ['example.test']);

        $domains = new InMemoryDomainStore();
        $domains->given(DomainFixture::domain('example.test', domainQuotaKib: 100000));
        $domains->given(DomainFixture::domain('fremd.test', prefix: 'fremd.test'));

        $this->accounts = new InMemoryAccountStore();
        $this->virtual = new InMemoryVirtualStore();
        $this->mailstore = new InMemoryMailstore();

        foreach (['info.example.test' => 'example.test', 'carl@example.test' => 'example.test', 'bert.fremd.test' => 'fremd.test'] as $username => $domain) {
            $record = $this->accounts->given($username, $domain);
            $this->mailstore->given($record->requireIdentity(), 512);
            $this->mailstore->setQuota($record->requireIdentity(), Quota::ofKibibytes(20000));
        }

        $this->mailstore->forget();

        $auditContext = new AuditRequestContext();
        $this->trail = new InMemoryAuditTrail(context: $auditContext);

        $context = Bootstrap::prepare(Paths::detect());
        $context->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($context, [
            AdminDirectory::class => $directory,
            RefreshTokenStore::class => new InMemoryRefreshTokenStore(),
            TokenDenylist::class => new InMemoryTokenDenylist(),
            LoginAttemptLog::class => new InMemoryLoginAttemptLog(),
            DomainStore::class => $domains,
            AccountStore::class => $this->accounts,
            // The catchall rows `DeleteAccount` looks at (U-128): in memory, never the real table.
            AddressStore::class => $this->virtual,
            Mailstore::class => $this->mailstore,
            RepairQueue::class => new InMemoryRepairQueue(),
            AuditWriter::class => $this->trail,
            AuditRequestContext::class => $auditContext,
            PasswordHasher::class => $hasher,
            CommonPasswordList::class => new InMemoryCommonPasswordList(),
        ]);
    }

    // -------------------------------------------------------------- listing

    public function testTheListingIsInTheContractsShape(): void
    {
        $response = $this->get('/api/v1/domains/example.test/accounts', $this->tokenFor('anna'));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/domains/{domain}/accounts', $response);

        $body = self::bodyOf($response);
        self::assertSame(2, $body['meta']['total']);
        self::assertSame(['carl@example.test', 'info.example.test'], array_column($body['items'], 'id'));
        self::assertSame('fqun', $body['items'][0]['naming_scheme']);
        self::assertArrayNotHasKey('password', $body['items'][0]);
    }

    public function testListingAForeignDomainAnswersExactlyLikeAMissingOne(): void
    {
        $token = $this->tokenFor('anna');

        $foreign = $this->get('/api/v1/domains/fremd.test/accounts', $token);
        $missing = $this->get('/api/v1/domains/gibtsnicht.test/accounts', $token);

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame(404, $missing->getStatusCode());
        $this->assertMatchesContract('get', '/domains/{domain}/accounts', $foreign);
        self::assertSame(self::withoutPerRequestFields(self::bodyOf($foreign)), self::withoutPerRequestFields(self::bodyOf($missing)));
    }

    public function testAnUndeclaredFilterIsReported(): void
    {
        $response = $this->get('/api/v1/domains/example.test/accounts?quota=1', $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('get', '/domains/{domain}/accounts', $response);
    }

    // ------------------------------------------------------------- creation

    public function testCreatingAnAccountAnswersWithLocationAndEtag(): void
    {
        $response = $this->post('/api/v1/domains/example.test/accounts', [
            'local_part' => 'Anna',
            'password' => self::ACCOUNT_PASSWORD,
        ], $this->tokenFor('anna'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('post', '/domains/{domain}/accounts', $response);
        self::assertSame('/api/v1/accounts/anna.example.test', $response->getHeaderLine('Location'));
        self::assertStringStartsWith('W/"', $response->getHeaderLine('ETag'));

        $body = self::bodyOf($response);
        self::assertSame('anna@example.test', $body['account']['address']);
        self::assertSame('bcrypt', $body['account']['password_format']);
        self::assertSame([], $body['incomplete_steps']);
        self::assertStringNotContainsString(self::ACCOUNT_PASSWORD, (string) $response->getBody());
        self::assertSame('account.create', $this->trail->last()?->action);
    }

    public function testCreatingInAForeignDomainIsNotFound(): void
    {
        $response = $this->post('/api/v1/domains/fremd.test/accounts', [
            'local_part' => 'anna',
            'password' => self::ACCOUNT_PASSWORD,
        ], $this->tokenFor('anna'));

        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('post', '/domains/{domain}/accounts', $response);
        self::assertSame([], $this->mailstore->operations());
    }

    public function testATakenNameIsAConflict(): void
    {
        $response = $this->post('/api/v1/domains/example.test/accounts', [
            'local_part' => 'info',
            'password' => self::ACCOUNT_PASSWORD,
        ], $this->tokenFor('root'));

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains/{domain}/accounts', $response);
        self::assertSame(AccountErrorCode::NAME_TAKEN, self::bodyOf($response)['code']);
    }

    public function testAWeakPasswordIsRefusedWithItsRuleAndNotEchoed(): void
    {
        $response = $this->post('/api/v1/domains/example.test/accounts', [
            'local_part' => 'anna',
            'password' => 'kurz',
        ], $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains/{domain}/accounts', $response);
        self::assertStringNotContainsString('kurz', (string) $response->getBody());
        self::assertSame([], $this->mailstore->operations());
    }

    public function testAZeroQuotaIsRefusedByTheContract(): void
    {
        $response = $this->post('/api/v1/domains/example.test/accounts', [
            'local_part' => 'anna',
            'password' => self::ACCOUNT_PASSWORD,
            'quota_kib' => 0,
        ], $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('request.validation_failed', self::bodyOf($response)['code']);
    }

    public function testAQuotaBeyondTheDomainAllowanceIsAConflict(): void
    {
        $response = $this->post('/api/v1/domains/example.test/accounts', [
            'local_part' => 'anna',
            'password' => self::ACCOUNT_PASSWORD,
            'quota_kib' => 70000,
        ], $this->tokenFor('root'));

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains/{domain}/accounts', $response);
        self::assertSame(AccountErrorCode::QUOTA_EXCEEDS_DOMAIN, self::bodyOf($response)['code']);
    }

    // ---------------------------------------------------------- one account

    public function testReadingAnAccountCarriesAnEtag(): void
    {
        $response = $this->get('/api/v1/accounts/info.example.test', $this->tokenFor('anna'));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/accounts/{account}', $response);
        self::assertStringStartsWith('W/"', $response->getHeaderLine('ETag'));
        self::assertSame('info', self::bodyOf($response)['local_part']);
    }

    public function testAnAtInTheIdentifierTravelsEncoded(): void
    {
        $response = $this->get('/api/v1/accounts/carl%40example.test', $this->tokenFor('anna'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('carl@example.test', self::bodyOf($response)['id']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function accountOperations(): iterable
    {
        yield 'get' => ['GET', '', '/accounts/{account}'];
        yield 'patch' => ['PATCH', '', '/accounts/{account}'];
        yield 'delete' => ['DELETE', '', '/accounts/{account}'];
        yield 'password' => ['PUT', '/password', '/accounts/{account}/password'];
        yield 'get quota' => ['GET', '/quota', '/accounts/{account}/quota'];
        yield 'set quota' => ['PUT', '/quota', '/accounts/{account}/quota'];
    }

    #[DataProvider('accountOperations')]
    public function testAForeignAccountAnswersExactlyLikeAMissingOne(string $method, string $suffix, string $path): void
    {
        $token = $this->tokenFor('anna');
        $body = match (true) {
            $method === 'PATCH' => ['services' => ['pop' => false]],
            $suffix === '/password' => ['password' => self::ACCOUNT_PASSWORD],
            $suffix === '/quota' && $method === 'PUT' => ['limit_kib' => 1000],
            default => null,
        };

        $foreign = $this->send($method, '/api/v1/accounts/bert.fremd.test' . $suffix, $body, $token, '*');
        $missing = $this->send($method, '/api/v1/accounts/niemand.example.test' . $suffix, $body, $token, '*');

        self::assertSame(404, $foreign->getStatusCode(), (string) $foreign->getBody());
        self::assertSame(404, $missing->getStatusCode());
        $this->assertMatchesContract(strtolower($method), $path, $foreign);
        self::assertSame(self::withoutPerRequestFields(self::bodyOf($foreign)), self::withoutPerRequestFields(self::bodyOf($missing)));
        self::assertSame([], $this->accounts->operations());
    }

    public function testChangingTheServiceFlags(): void
    {
        $token = $this->tokenFor('anna');
        $etag = $this->get('/api/v1/accounts/info.example.test', $token)->getHeaderLine('ETag');

        $response = $this->send('PATCH', '/api/v1/accounts/info.example.test', ['services' => ['pop' => false]], $token, $etag);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('patch', '/accounts/{account}', $response);
        self::assertFalse(self::bodyOf($response)['services']['pop']);

        $stale = $this->send('PATCH', '/api/v1/accounts/info.example.test', ['services' => ['imap' => false]], $token, $etag);
        self::assertSame(412, $stale->getStatusCode());
        $this->assertMatchesContract('patch', '/accounts/{account}', $stale);
    }

    public function testDisablingAndEnablingAnAccount(): void
    {
        $token = $this->tokenFor('anna');

        $disabled = $this->send('PATCH', '/api/v1/accounts/info.example.test', ['enabled' => false], $token, null);

        self::assertSame(200, $disabled->getStatusCode(), (string) $disabled->getBody());
        $this->assertMatchesContract('patch', '/accounts/{account}', $disabled);
        self::assertFalse(self::bodyOf($disabled)['enabled']);
        self::assertSame('anna', self::bodyOf($disabled)['disabled_by']);
        self::assertIsString(self::bodyOf($disabled)['disabled_at']);
        self::assertCount(1, $this->trail->withAction('account.disable'));

        $enabled = $this->send('PATCH', '/api/v1/accounts/info.example.test', ['enabled' => true], $token, null);

        self::assertSame(200, $enabled->getStatusCode());
        $this->assertMatchesContract('patch', '/accounts/{account}', $enabled);
        self::assertTrue(self::bodyOf($enabled)['enabled']);
        self::assertNull(self::bodyOf($enabled)['disabled_at']);
        self::assertNull(self::bodyOf($enabled)['disabled_by']);
    }

    public function testAnEmptyChangeIsRefusedByTheContract(): void
    {
        $response = $this->send('PATCH', '/api/v1/accounts/info.example.test', [], $this->tokenFor('anna'), null);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->accounts->operations());
    }

    public function testADisableTheViewIgnoresIsAConflict(): void
    {
        $this->accounts->viewIgnoresStatus();

        $response = $this->send('PATCH', '/api/v1/accounts/info.example.test', ['enabled' => false], $this->tokenFor('anna'), null);

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('patch', '/accounts/{account}', $response);
        self::assertSame(AccountErrorCode::STATUS_NOT_ENFORCED, self::bodyOf($response)['code']);
        self::assertTrue($this->accounts->find('info.example.test')?->status->enabled);
    }

    public function testDeletingRequiresIfMatchAndThenAnswersWithNoContent(): void
    {
        $token = $this->tokenFor('anna');

        $unconditional = $this->send('DELETE', '/api/v1/accounts/info.example.test', null, $token, null);
        self::assertSame(428, $unconditional->getStatusCode());
        $this->assertMatchesContract('delete', '/accounts/{account}', $unconditional);

        $etag = $this->get('/api/v1/accounts/info.example.test', $token)->getHeaderLine('ETag');
        $response = $this->send('DELETE', '/api/v1/accounts/info.example.test', null, $token, $etag);

        self::assertSame(204, $response->getStatusCode());
        $this->assertMatchesContract('delete', '/accounts/{account}', $response);
        self::assertFalse($this->accounts->has('info.example.test'));
        self::assertFalse($this->mailstore->mailboxExists(self::identity('info.example.test')));
    }

    public function testDeletingTakesTheCatchallWithIt(): void
    {
        $token = $this->tokenFor('anna');
        $this->virtual->givenRow('@example.test', 'info@example.test');

        $etag = $this->get('/api/v1/accounts/info.example.test', $token)->getHeaderLine('ETag');
        $response = $this->send('DELETE', '/api/v1/accounts/info.example.test', null, $token, $etag);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([], $this->virtual->all());
        self::assertCount(1, $this->trail->withAction('catchall.delete'));
    }

    public function testSettingThePassword(): void
    {
        $token = $this->tokenFor('anna');

        $response = $this->send('PUT', '/api/v1/accounts/info.example.test/password', ['password' => self::ACCOUNT_PASSWORD], $token, null);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('put', '/accounts/{account}/password', $response);
        self::assertStringStartsWith('$2y$', (string) $this->accounts->hashOf('info.example.test'));

        $weak = $this->send('PUT', '/api/v1/accounts/info.example.test/password', ['password' => 'info-info-info'], $token, null);
        self::assertSame(422, $weak->getStatusCode());
        $this->assertMatchesContract('put', '/accounts/{account}/password', $weak);
    }

    public function testReadingAndSettingTheQuota(): void
    {
        $token = $this->tokenFor('anna');

        $read = $this->get('/api/v1/accounts/info.example.test/quota', $token);
        self::assertSame(200, $read->getStatusCode());
        $this->assertMatchesContract('get', '/accounts/{account}/quota', $read);
        self::assertSame(['limit_kib' => 20000, 'used_kib' => 512], self::bodyOf($read));

        $lowered = $this->send('PUT', '/api/v1/accounts/info.example.test/quota', ['limit_kib' => 10000], $token, null);
        self::assertSame(200, $lowered->getStatusCode(), (string) $lowered->getBody());
        $this->assertMatchesContract('put', '/accounts/{account}/quota', $lowered);
        self::assertSame(10000, self::bodyOf($lowered)['limit_kib']);

        $unlimited = $this->send('PUT', '/api/v1/accounts/info.example.test/quota', ['limit_kib' => null], $token, null);
        self::assertSame(409, $unlimited->getStatusCode());
        $this->assertMatchesContract('put', '/accounts/{account}/quota', $unlimited);
        self::assertSame(AccountErrorCode::QUOTA_EXCEEDS_DOMAIN, self::bodyOf($unlimited)['code']);
    }

    // ------------------------------------------------------------ generally

    public function testNoneOfTheseEndpointsIsReachableWithoutAToken(): void
    {
        self::assertSame(401, $this->get('/api/v1/domains/example.test/accounts', null)->getStatusCode());
        self::assertSame(401, $this->get('/api/v1/accounts/info.example.test', null)->getStatusCode());
        self::assertSame(401, $this->get('/api/v1/accounts/info.example.test/quota', null)->getStatusCode());
        self::assertSame(401, $this->send('PUT', '/api/v1/accounts/info.example.test/password', ['password' => self::ACCOUNT_PASSWORD], null, null)->getStatusCode());
    }

    public function testTheContractOffersExactlyTheSortFieldsTheRepositoryImplements(): void
    {
        /** @var array<string, mixed> $parameter */
        $parameter = self::contract()['components']['parameters']['AccountSort'];

        self::assertSame(AccountRepository::sortAllowlist()->keys(), $parameter['schema']['enum']);
        self::assertSame(AccountRepository::sortAllowlist()->defaultKey(), $parameter['schema']['default']);
    }

    public function testEveryErrorCodeA2RaisesIsInTheContractEnumeration(): void
    {
        /** @var list<string> $enum */
        $enum = self::contract()['components']['schemas']['ErrorCode']['enum'];

        foreach ([...AccountErrorCode::all(), AccountErrorCode::IDENTITY_MISMATCH] as $code) {
            self::assertContains($code, $enum, sprintf('AccountErrorCode "%s" is not in the contract.', $code));
        }
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
            'password' => self::ADMIN_PASSWORD,
        ], null);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) self::bodyOf($response)['access_token'];
    }

    private static function identity(string $storedUsername, string $domain = 'example.test'): MailboxIdentity
    {
        return MailboxIdentity::fromStoredUsername($storedUsername, DomainName::fromStoredValue($domain));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function post(string $path, ?array $body, ?string $token): ResponseInterface
    {
        return $this->send('POST', $path, $body, $token, null);
    }

    private function get(string $path, ?string $token): ResponseInterface
    {
        return $this->send('GET', $path, null, $token, null);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body, ?string $token, ?string $ifMatch): ResponseInterface
    {
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

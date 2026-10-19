<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Application\Admin\AdminPreferences;
use Mailadmin\Application\Admin\AdminPreferenceStore;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\InterfaceLocale;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminPreferenceStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Security\LoginAttemptLog;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * W2's two operations, driven through the container the application really
 * boots, with every answer checked against the contract — and the places where
 * the contract and the code list the same thing twice.
 */
#[CoversNothing]
final class OwnPreferencesEndpointTest extends ContractTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';
    private const string PATH = '/admins/me/preferences';

    private ContainerInterface $container;
    private InMemoryAdminPreferenceStore $store;
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

        $this->store = new InMemoryAdminPreferenceStore();

        $auditContext = new AuditRequestContext();
        $this->trail = new InMemoryAuditTrail(context: $auditContext);

        $context = Bootstrap::prepare(Paths::detect());
        $context->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($context, [
            AdminDirectory::class => $directory,
            RefreshTokenStore::class => new InMemoryRefreshTokenStore(),
            TokenDenylist::class => new InMemoryTokenDenylist(),
            LoginAttemptLog::class => new InMemoryLoginAttemptLog(),
            AdminPreferenceStore::class => $this->store,
            AuditWriter::class => $this->trail,
            AuditRequestContext::class => $auditContext,
        ]);
    }

    public function testNothingChosenIsNullInTheContractsShape(): void
    {
        $response = $this->send('GET', null, $this->tokenFor('anna'));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', $response);
        self::assertSame(['locale' => null], self::bodyOf($response));
    }

    public function testADomainMasterStoresAndReadsBackTheirLanguage(): void
    {
        $token = $this->tokenFor('anna');

        $put = $this->send('PUT', ['locale' => 'de'], $token);

        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        $this->assertMatchesContract('put', $put);
        self::assertSame(['locale' => 'de'], self::bodyOf($put));
        self::assertSame('de', $this->store->storedLocale('anna'));

        $get = $this->send('GET', null, $token);

        self::assertSame(['locale' => 'de'], self::bodyOf($get));

        $entry = $this->trail->last();

        self::assertNotNull($entry);
        self::assertSame('admin.preferences.change', $entry->action);
        self::assertSame('anna', $entry->actor);
    }

    public function testNullFollowsTheBrowserAgain(): void
    {
        $token = $this->tokenFor('root');
        $this->send('PUT', ['locale' => 'de'], $token);

        $response = $this->send('PUT', ['locale' => null], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('put', $response);
        self::assertSame(['locale' => null], self::bodyOf($response));
        self::assertNull($this->store->storedLocale('root'));
    }

    public function testALanguageThatIsNotOfferedIsRejectedAtTheBoundary(): void
    {
        $response = $this->send('PUT', ['locale' => 'en'], $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('put', $response);
        self::assertSame('request.validation_failed', self::bodyOf($response)['code']);
        self::assertSame([], $this->trail->withAction('admin.preferences.change'));
    }

    public function testAnOmittedMemberIsNotLeaveAsIs(): void
    {
        $response = $this->send('PUT', [], $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('put', $response);
    }

    public function testNeitherOperationIsReachableWithoutAToken(): void
    {
        self::assertSame(401, $this->send('GET', null, null)->getStatusCode());
        self::assertSame(401, $this->send('PUT', ['locale' => 'de'], null)->getStatusCode());
    }

    /**
     * U-89: `InterfaceLocale` in the contract and the PHP enum are one list. The
     * frontend holds the same contract enum against its catalogues.
     */
    public function testTheLocaleEnumerationMatchesTheDomainEnum(): void
    {
        /** @var list<string> $declared */
        $declared = self::contract()['components']['schemas']['InterfaceLocale']['enum'];

        $known = array_map(static fn (InterfaceLocale $l): string => $l->value, InterfaceLocale::cases());

        sort($declared);
        sort($known);

        self::assertSame($known, $declared);
    }

    public function testThePreferencesSchemaDescribesExactlyWhatIsSent(): void
    {
        /** @var array<string, mixed> $schema */
        $schema = self::contract()['components']['schemas']['AdminPreferences'];

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];

        $sent = array_keys(AdminPreferences::none()->representation());
        $declared = array_keys($properties);

        sort($sent);
        sort($declared);

        self::assertSame($sent, $declared);
        self::assertSame($sent, $schema['required']);
    }

    // -----------------------------------------------------------------

    private function assertMatchesContract(string $method, ResponseInterface $response): void
    {
        (new ValidatorBuilder())
            ->fromYamlFile(self::projectRoot() . '/var/cache/openapi.bundled.yaml')
            ->getResponseValidator()
            ->validate(new OperationAddress(self::PATH, $method), $response);

        $this->addToAssertionCount(1);
    }

    private function tokenFor(string $username): string
    {
        $response = $this->request('POST', '/api/v1/auth/token', [
            'username' => $username,
            'password' => self::PASSWORD,
        ], null);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) self::bodyOf($response)['access_token'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, ?array $body, ?string $token): ResponseInterface
    {
        return $this->request($method, '/api/v1' . self::PATH, $body, $token);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?array $body, ?string $token): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, $factory->createUri('https://mail.example.test' . $path));

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode((object) $body, JSON_THROW_ON_ERROR)));
        }

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
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
}

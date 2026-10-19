<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Http\RefreshCookie;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Security\AuthenticationConfiguration;
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
 * The four `/auth/*` endpoints, driven through the container the application
 * really boots, with every answer checked against the contract.
 *
 * ## What is doubled, and what is not
 *
 * Only the four things that would otherwise reach MySQL — the administrator
 * directory, the refresh store, the denylist and the attempt log. Everything
 * else is the real thing: the real middleware chain, the real dispatcher, the
 * real route table compiled from `openapi/`, the real token issuer signing with
 * the real algorithm.
 *
 * That split is deliberate. A test that also doubled the token issuer would
 * prove that the handlers call their collaborators; this one proves that a token
 * this application mints is a token this application accepts, over the wire, in
 * the shape the contract describes.
 */
#[CoversNothing]
final class AuthEndpointTest extends ContractTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;
    private InMemoryAdminDirectory $directory;
    private InMemoryRefreshTokenStore $store;

    protected function setUp(): void
    {
        self::routeTable();

        $hasher = new PasswordHasher(PasswordHashingOptions::of(
            bcryptCost: 4,
            argon2MemoryKib: 8192,
            argon2TimeCost: 1,
            argon2Threads: 1,
        ));

        $this->directory = new InMemoryAdminDirectory();
        $this->directory->given(
            'anna',
            $hasher->hash(Password::alreadyInUse(self::PASSWORD, PasswordSubject::AdminUser)),
            AdminRole::DomainMaster,
            ['example.test'],
        );

        $this->store = new InMemoryRefreshTokenStore();

        $context = Bootstrap::prepare(Paths::detect());
        $context->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($context, [
            AdminDirectory::class => $this->directory,
            RefreshTokenStore::class => $this->store,
            TokenDenylist::class => new InMemoryTokenDenylist(),
            LoginAttemptLog::class => new InMemoryLoginAttemptLog(),
            // The fifth thing that would otherwise reach MySQL (S4). Signing in
            // records an entry, and a writer pointed at no database would turn
            // every case here into a logged write failure.
            AuditWriter::class => new InMemoryAuditTrail(),
        ]);
    }

    public function testSigningInAnswersWithASessionAndMatchesTheContract(): void
    {
        $response = $this->post('/api/v1/auth/token', [
            'username' => 'anna',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('post', '/auth/token', $response);

        $body = self::bodyOf($response);

        self::assertSame('Bearer', $body['token_type']);

        // The configured lifetime, not a literal 3600: the test environment
        // deliberately runs at the floor AuthenticationConfiguration allows, and
        // a hard-coded number here would fail there for no reason.
        $configuration = $this->container->get(AuthenticationConfiguration::class);
        self::assertInstanceOf(AuthenticationConfiguration::class, $configuration);
        self::assertSame($configuration->accessTokenTtl, $body['expires_in']);
    }

    /**
     * The default delivery. A refresh token in the body would be readable by any
     * script that can read the response — which is the thing the HttpOnly cookie
     * exists to prevent (E-15).
     */
    public function testWithCookieDeliveryTheBodyCarriesNoRefreshToken(): void
    {
        $response = $this->post('/api/v1/auth/token', [
            'username' => 'anna',
            'password' => self::PASSWORD,
        ]);

        self::assertArrayNotHasKey('refresh_token', self::bodyOf($response));
        self::assertStringContainsString('HttpOnly', $response->getHeaderLine('Set-Cookie'));
    }

    public function testWithBodyDeliveryThereIsNoCookie(): void
    {
        $response = $this->post('/api/v1/auth/token', [
            'username' => 'anna',
            'password' => self::PASSWORD,
            'refresh_delivery' => 'body',
        ]);

        self::assertArrayHasKey('refresh_token', self::bodyOf($response));
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testAWrongPasswordIsAProblemDocumentInTheContractsShape(): void
    {
        $response = $this->post('/api/v1/auth/token', [
            'username' => 'anna',
            'password' => 'not-the-password',
        ]);

        self::assertSame(401, $response->getStatusCode());
        $this->assertMatchesContract('post', '/auth/token', $response);

        self::assertSame('auth.invalid_credentials', self::bodyOf($response)['code']);
    }

    /**
     * Schema validation happens before any handler runs, so a body without a
     * password is a `422` with the field named — not a `401` that looks like a
     * wrong password.
     */
    public function testABodyThatDoesNotMatchTheSchemaIsRejectedAtTheBoundary(): void
    {
        $response = $this->post('/api/v1/auth/token', ['username' => 'anna']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('request.validation_failed', self::bodyOf($response)['code']);
    }

    public function testTheWholeRoundTripWorksOverTheWire(): void
    {
        $signIn = $this->post('/api/v1/auth/token', [
            'username' => 'anna',
            'password' => self::PASSWORD,
            'refresh_delivery' => 'body',
        ]);

        $accessToken = (string) self::bodyOf($signIn)['access_token'];
        $refreshToken = (string) self::bodyOf($signIn)['refresh_token'];

        // The token this application just minted is accepted by the middleware
        // it really runs.
        $me = $this->get('/api/v1/auth/me', $accessToken);

        self::assertSame(200, $me->getStatusCode());
        $this->assertMatchesContract('get', '/auth/me', $me);
        self::assertSame('anna', self::bodyOf($me)['username']);
        self::assertSame('domainmaster', self::bodyOf($me)['role']);
        self::assertSame(['example.test'], self::bodyOf($me)['domains']);
        self::assertSame(
            RolePermissions::of(AdminRole::DomainMaster)->toStrings(),
            self::bodyOf($me)['permissions'],
        );

        // Refreshing rotates it.
        $refreshed = $this->post('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);

        self::assertSame(200, $refreshed->getStatusCode());
        $this->assertMatchesContract('post', '/auth/refresh', $refreshed);
        self::assertNotSame($refreshToken, self::bodyOf($refreshed)['refresh_token']);

        // Signing out kills the access token at once.
        $logout = $this->post('/api/v1/auth/logout', null, $accessToken);

        self::assertSame(204, $logout->getStatusCode());
        self::assertSame(401, $this->get('/api/v1/auth/me', $accessToken)->getStatusCode());
    }

    /**
     * The reuse alarm, end to end.
     */
    public function testASpentRefreshTokenIsReportedAsAReuse(): void
    {
        $signIn = $this->post('/api/v1/auth/token', [
            'username' => 'anna',
            'password' => self::PASSWORD,
            'refresh_delivery' => 'body',
        ]);

        $first = (string) self::bodyOf($signIn)['refresh_token'];

        $this->post('/api/v1/auth/refresh', ['refresh_token' => $first]);
        $again = $this->post('/api/v1/auth/refresh', ['refresh_token' => $first]);

        self::assertSame(401, $again->getStatusCode());
        $this->assertMatchesContract('post', '/auth/refresh', $again);
        self::assertSame('auth.refresh_reused', self::bodyOf($again)['code']);
    }

    public function testTheRefreshCookieIsEnoughForABrowser(): void
    {
        $signIn = $this->post('/api/v1/auth/token', [
            'username' => 'anna',
            'password' => self::PASSWORD,
        ]);

        $cookie = self::cookieValueOf($signIn);

        // `{}` and not a body-less POST: the contract says so, because request
        // validation needs a Content-Type to check a body against the operation.
        $refreshed = $this->post('/api/v1/auth/refresh', [], null, [RefreshCookie::NAME => $cookie]);

        self::assertSame(200, $refreshed->getStatusCode());
        self::assertArrayNotHasKey('refresh_token', self::bodyOf($refreshed));
        self::assertStringContainsString('HttpOnly', $refreshed->getHeaderLine('Set-Cookie'));
    }

    public function testTheseEndpointsAreNotReachableWithoutATokenWhereTheContractSaysSo(): void
    {
        self::assertSame(401, $this->get('/api/v1/auth/me', null)->getStatusCode());
        self::assertSame(401, $this->post('/api/v1/auth/logout', null, null)->getStatusCode());
    }

    /**
     * The two that mint a session must stay reachable without a bearer token, or
     * nobody could ever obtain the first one.
     *
     * Both answer `401` for an unusable *credential*, which is why the assertion
     * is on the code rather than on the status: `auth.refresh_invalid` means the
     * refresh token was examined and found wanting, while
     * `request.unauthenticated` would mean the authentication middleware turned
     * the request away before the handler ever ran.
     */
    public function testTheEndpointsThatMintASessionNeedNoBearerToken(): void
    {
        $refresh = $this->post('/api/v1/auth/refresh', ['refresh_token' => 'never-issued']);

        self::assertSame(401, $refresh->getStatusCode());
        self::assertSame('auth.refresh_invalid', self::bodyOf($refresh)['code']);

        $signIn = $this->post('/api/v1/auth/token', ['username' => 'anna', 'password' => 'wrong']);

        self::assertSame('auth.invalid_credentials', self::bodyOf($signIn)['code']);
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

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $cookies
     */
    private function post(
        string $path,
        ?array $body = null,
        ?string $accessToken = null,
        array $cookies = [],
    ): ResponseInterface {
        $factory = new Psr17Factory();
        $request = $factory
            ->createServerRequest('POST', 'https://mail.example.test' . $path)
            ->withCookieParams($cookies);

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        if ($accessToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $accessToken);
        }

        return $this->chain()->handle($request);
    }

    private function get(string $path, ?string $accessToken): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://mail.example.test' . $path);

        if ($accessToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $accessToken);
        }

        return $this->chain()->handle($request);
    }

    private function chain(): MiddlewareDispatcher
    {
        $chain = $this->container->get(MiddlewareDispatcher::class);
        self::assertInstanceOf(MiddlewareDispatcher::class, $chain);

        return $chain;
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

    private static function cookieValueOf(ResponseInterface $response): string
    {
        $header = $response->getHeaderLine('Set-Cookie');

        if (preg_match('/^' . RefreshCookie::NAME . '=([^;]+)/', $header, $matches) !== 1) {
            self::fail('The response set no refresh cookie: ' . $header);
        }

        return rawurldecode($matches[1]);
    }
}

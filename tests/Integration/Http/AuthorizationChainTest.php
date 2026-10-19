<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Http;

use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Middleware\AuditMiddleware;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Http\Middleware\AuthorizationMiddleware;
use Mailadmin\Http\Middleware\ErrorHandlerMiddleware;
use Mailadmin\Http\Middleware\JsonBodyMiddleware;
use Mailadmin\Http\Middleware\OpenApiValidationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Middleware\SecurityHeadersMiddleware;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Http\NotFoundHandler;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Http\RouteMiddleware;
use Mailadmin\Http\Routing\ContractBuild;
use Mailadmin\Http\Routing\DocumentationEndpoint;
use Mailadmin\Http\Routing\RouteCompiler;
use Mailadmin\Http\Routing\SpecificationBundler;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Security\SigningKey;
use Mailadmin\Infrastructure\Security\SigningKeys;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Infrastructure\Security\TokenVerifier;
use Mailadmin\Log\RequestIdProcessor;
use Mailadmin\Runtime\CacheDirectory;
use Mailadmin\Runtime\SystemClock;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * S3's definition of done, over the wire: **401 without a token, 403 with a
 * valid token and the wrong role, 200 with the right one.**
 *
 * It runs against the fixture contract rather than the real one because the real
 * contract has no operation yet that a domain master may not perform — every
 * `/auth/*` operation is held by both roles. `POST /widgets` in the fixture
 * declares `domain.write`, which is superuser-only, so this is the first place
 * the two roles can actually be told apart end to end.
 *
 * Nothing is doubled except the administrator directory and the denylist, which
 * would otherwise reach MySQL. The token is minted and verified by the real
 * classes with a real Ed25519 key, the chain is assembled from
 * {@see RouteMiddleware} — the same class the container uses — and the response
 * is checked against the contract by the real validator.
 */
#[CoversClass(AuthorizationMiddleware::class)]
#[CoversClass(RouteMiddleware::class)]
final class AuthorizationChainTest extends TestCase
{
    /** 32 zero bytes. A key with no secrecy to lose, in a file that ships. */
    private const string SEED = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private string $cacheDirectory;
    private InMemoryAdminDirectory $directory;
    private InMemoryAuditTrail $audit;
    private TokenIssuer $issuer;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/mailadmin-authz-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDirectory, 0o777, true);

        $this->directory = new InMemoryAdminDirectory();
        $this->directory->given('root', 'x', AdminRole::Superuser, []);
        $this->directory->given('master', 'x', AdminRole::DomainMaster, ['own.example']);

        $this->audit = new InMemoryAuditTrail();

        $this->issuer = new TokenIssuer(
            keys: self::keys(),
            clock: new SystemClock(),
            issuer: 'mailadmin-test',
            audience: 'mailadmin-test',
            lifetimeSeconds: 300,
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->cacheDirectory);
    }

    public function testWithoutATokenTheAnswerIsFourOhOneAndTheHandlerNeverRuns(): void
    {
        $response = $this->createWidget(token: null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('request.unauthenticated', $this->body($response)['code']);
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testWithAValidTokenAndTheWrongRoleTheAnswerIsFourOhThree(): void
    {
        $response = $this->createWidget($this->tokenFor('master', AdminRole::DomainMaster));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('request.forbidden', $this->body($response)['code']);
    }

    public function testTheForbiddenAnswerDoesNotSayWhatWouldHaveBeenNeeded(): void
    {
        $document = (string) $this->createWidget($this->tokenFor('master', AdminRole::DomainMaster))->getBody();

        self::assertStringNotContainsString(Permission::DomainWrite->value, $document);
        self::assertStringNotContainsString('superuser', $document);
    }

    public function testWithTheRightRoleTheHandlerRuns(): void
    {
        $response = $this->createWidget($this->tokenFor('root', AdminRole::Superuser));

        self::assertSame(201, $response->getStatusCode());
    }

    public function testAPublicOperationStillNeedsNoToken(): void
    {
        // The regression that would hurt most: authorisation running before it
        // has looked at `x-permission` would make signing in impossible.
        $response = $this->handle(new ServerRequest('GET', '/api/v1/widgets'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheDeletedAdministratorLosesEverythingAtOnce(): void
    {
        $token = $this->tokenFor('root', AdminRole::Superuser);

        self::assertSame(201, $this->createWidget($token)->getStatusCode());

        $this->directory->forget('root');

        // 401, not 403: there is nobody left to have a role. Authentication
        // reaches that conclusion before authorisation is asked anything.
        self::assertSame(401, $this->createWidget($token)->getStatusCode());
    }

    public function testADemotedAdministratorIsRefusedWithinTheHourAndNotAfterIt(): void
    {
        $token = $this->tokenFor('root', AdminRole::Superuser);

        self::assertSame(201, $this->createWidget($token)->getStatusCode());

        // The role in the token no longer matches the database. S2 answers 401
        // and the client's correct reaction is to fetch a token that tells the
        // truth — which will then be refused with 403 by this middleware.
        $this->directory->given('root', 'x', AdminRole::DomainMaster, ['own.example']);

        self::assertSame(401, $this->createWidget($token)->getStatusCode());
        self::assertSame(403, $this->createWidget($this->tokenFor('root', AdminRole::DomainMaster))->getStatusCode());
    }

    public function testTheOrderOfTheChainIsAuthenticationAuditThenAuthorisation(): void
    {
        $chain = $this->routeMiddleware()->all();

        // The audit middleware sits between the two and could not sit anywhere
        // else: it needs the actor attribute, which exists only inside
        // authentication, and it needs to see the `403`, which exists only
        // outside authorisation (S4).
        self::assertCount(3, $chain);
        self::assertInstanceOf(AuthenticationMiddleware::class, $chain[0]);
        self::assertInstanceOf(AuditMiddleware::class, $chain[1]);
        self::assertInstanceOf(AuthorizationMiddleware::class, $chain[2]);
    }

    /**
     * S4's definition of done, on the path S3 built: a refused attempt leaves an
     * entry saying who tried what, and saying that it was refused.
     */
    public function testARefusedAttemptIsRecordedInTheAuditTrail(): void
    {
        $this->createWidget($this->tokenFor('master', AdminRole::DomainMaster));

        $entry = $this->audit->last();

        self::assertNotNull($entry);
        self::assertSame('master', $entry->actor);
        self::assertSame(Permission::DomainWrite->value, $entry->action);
        self::assertSame('domain', $entry->objectType);
        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertStringContainsString('POST /api/v1/widgets', $entry->reason);
    }

    /**
     * The counterpart, and the reason the middleware inspects the status rather
     * than recording every request: an operation that went through is recorded
     * by the use case that changed something, not twice.
     */
    public function testAPermittedRequestLeavesNoRefusalBehind(): void
    {
        $this->createWidget($this->tokenFor('root', AdminRole::Superuser));

        self::assertTrue($this->audit->isEmpty());
    }

    /**
     * There is nobody to name. A `401` says the token was unusable, and the
     * sign-in path is where an unusable credential is recorded.
     */
    public function testAnUnauthenticatedRequestLeavesNoEntry(): void
    {
        $this->createWidget(token: null);

        self::assertTrue($this->audit->isEmpty());
    }

    // ------------------------------------------------------------------

    private function createWidget(?string $token): ResponseInterface
    {
        $request = (new ServerRequest('POST', '/api/v1/widgets'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create('{"name":"a widget"}'));

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->handle($request);
    }

    private function tokenFor(string $username, AdminRole $role): string
    {
        return $this->issuer->issue($username, $role)->value;
    }

    private function handle(ServerRequest $request): ResponseInterface
    {
        $factory = new Psr17Factory();
        $problems = new ProblemResponseFactory($factory, $factory);
        $logger = new NullLogger();
        $artefacts = $this->build();

        $dispatcher = new Dispatcher(
            artefacts: $artefacts,
            handlers: $this->container(),
            problems: $problems,
            notFound: new NotFoundHandler($problems),
            logger: $logger,
            documentation: new DocumentationEndpoint($artefacts, $factory, $factory, enabled: false, basePath: ''),
            basePath: '',
            requireValidatedRequests: true,
            routeMiddleware: $this->routeMiddleware($problems, $logger)->all(),
        );

        return (new MiddlewareDispatcher(
            $dispatcher,
            new RequestIdMiddleware(new RequestIdProcessor()),
            new SecurityHeadersMiddleware(),
            new ErrorHandlerMiddleware($logger, $problems),
            new JsonBodyMiddleware($problems),
            new OpenApiValidationMiddleware($artefacts, $problems, $logger, basePath: '', validateResponses: true),
        ))->handle($request);
    }

    private function routeMiddleware(?ProblemResponseFactory $problems = null, ?NullLogger $logger = null): RouteMiddleware
    {
        $factory = new Psr17Factory();
        $problems ??= new ProblemResponseFactory($factory, $factory);
        $logger ??= new NullLogger();

        return new RouteMiddleware(
            new AuthenticationMiddleware(
                new TokenVerifier(
                    keys: self::keys(),
                    clock: new SystemClock(),
                    issuer: 'mailadmin-test',
                    audience: 'mailadmin-test',
                ),
                new InMemoryTokenDenylist(),
                $this->directory,
                $problems,
                $logger,
            ),
            new AuditMiddleware(new AuditRequestContext(), $this->audit, $logger),
            new AuthorizationMiddleware($problems, $logger),
        );
    }

    private function build(): ContractBuild
    {
        return new ContractBuild(
            bundler: new SpecificationBundler(__DIR__ . '/Fixtures/contract'),
            compiler: new RouteCompiler(
                dirname(__DIR__, 2) . '/Unit/Http/Routing/Fixtures/Handler',
                'Mailadmin\\Tests\\Unit\\Http\\Routing\\Fixtures\\Handler',
            ),
            cache: new CacheDirectory($this->cacheDirectory),
            mayRebuild: true,
        );
    }

    private function container(): ContainerInterface
    {
        return new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new $id();
            }

            public function has(string $id): bool
            {
                return class_exists($id);
            }
        };
    }

    private static function keys(): SigningKeys
    {
        return SigningKeys::of(SigningKey::fromBase64Seed(self::SEED));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

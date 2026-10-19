<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Middleware;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Security\SigningKey;
use Mailadmin\Infrastructure\Security\SigningKeys;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Infrastructure\Security\TokenVerifier;
use Mailadmin\Tests\Support\CallableHandler;
use Mailadmin\Tests\Support\FrozenClock;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * Who a request is from — and every way that question can fail.
 *
 * Two properties carry the package. **A public operation is never touched**, or
 * a browser holding a stale token could not sign in again. And **every refusal
 * looks the same from outside**: an expired signature, a revoked token and a
 * deleted account all produce one `401` with one code, because the differences
 * would tell a caller how close they are.
 */
#[CoversClass(AuthenticationMiddleware::class)]
final class AuthenticationMiddlewareTest extends TestCase
{
    private const string SEED = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private FrozenClock $clock;
    private InMemoryAdminDirectory $directory;
    private InMemoryTokenDenylist $denylist;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->directory = new InMemoryAdminDirectory();
        $this->directory->given('anna', 'hash-is-irrelevant-here', AdminRole::Superuser);
        $this->denylist = new InMemoryTokenDenylist();
    }

    /**
     * `x-permission: public` means the header is not even looked at. A browser
     * sends whatever is in `localStorage` with the very request meant to replace
     * it, and rejecting `POST /auth/token` because the old token expired would
     * make signing in impossible exactly when it is needed.
     */
    public function testAPublicOperationIsNotTouchedEvenWithARottenToken(): void
    {
        $response = $this->dispatch(
            $this->request(permission: 'public')->withHeader('Authorization', 'Bearer utter-nonsense'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAValidTokenPutsTheActorAndTheClaimsIntoTheRequest(): void
    {
        $seen = null;

        $response = $this->dispatch(
            $this->authenticatedRequest('anna'),
            function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request;

                return (new Psr17Factory())->createResponse(200);
            },
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(ServerRequestInterface::class, $seen);
        self::assertInstanceOf(
            ActorContext::class,
            $seen->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR),
        );
        self::assertInstanceOf(
            TokenClaims::class,
            $seen->getAttribute(AuthenticationMiddleware::ATTRIBUTE_CLAIMS),
        );
    }

    /**
     * The actor comes from the database, not from the token — that is what makes
     * a withdrawn domain assignment take effect at once (architecture 5.3).
     */
    public function testTheScopeComesFromTheDatabaseAndNotFromTheToken(): void
    {
        $this->directory->given('bruno', 'hash', AdminRole::DomainMaster, ['example.test', 'zweite.test']);

        $seen = null;

        $this->dispatch(
            $this->authenticatedRequest('bruno', AdminRole::DomainMaster),
            function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);

                return (new Psr17Factory())->createResponse(200);
            },
        );

        self::assertInstanceOf(ActorContext::class, $seen);
        self::assertSame(['example.test', 'zweite.test'], $seen->scope->domainNames());
    }

    public function testNoTokenIsRefused(): void
    {
        $this->assertRefused($this->dispatch($this->request()));
    }

    public function testSomethingThatIsNotABearerTokenIsRefused(): void
    {
        $this->assertRefused(
            $this->dispatch($this->request()->withHeader('Authorization', 'Basic YWJjOmRlZg==')),
        );
    }

    public function testAGarbledTokenIsRefused(): void
    {
        $this->assertRefused(
            $this->dispatch($this->request()->withHeader('Authorization', 'Bearer not.a.token')),
        );
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $request = $this->authenticatedRequest('anna');

        $this->clock->advance(3600 + TokenVerifier::LEEWAY_SECONDS + 1);

        $this->assertRefused($this->dispatch($request));
    }

    /**
     * The denylist read is what makes signing out mean *now* (architecture 5.4).
     */
    public function testARevokedTokenIsRefused(): void
    {
        $token = $this->issuer()->issue('anna', AdminRole::Superuser);
        $this->denylist->denyToken($this->verifier()->verify($token->value), TokenDenylist::REASON_LOGOUT);

        $this->assertRefused(
            $this->dispatch($this->request()->withHeader('Authorization', 'Bearer ' . $token->value)),
        );
    }

    /**
     * `adminuser` has no status column, and S2 does not add one: the per-request
     * lookup the domain scope needs anyway answers this for free.
     */
    public function testARemovedAdministratorIsRefusedWithATokenThatStillVerifies(): void
    {
        $request = $this->authenticatedRequest('anna');

        $this->directory->forget('anna');

        $this->assertRefused($this->dispatch($request));
    }

    /**
     * A superuser demoted to domain master would otherwise keep superuser rights
     * for the rest of the hour.
     */
    public function testATokenWhoseRoleNoLongerMatchesIsRefused(): void
    {
        $request = $this->authenticatedRequest('anna', AdminRole::Superuser);

        $this->directory->given('anna', 'hash', AdminRole::DomainMaster, ['example.test']);

        $this->assertRefused($this->dispatch($request));
    }

    /**
     * Every rejection is the same document. The reason lives in the log — see
     * the class comment for why it must not live in the response.
     */
    public function testEveryRefusalLooksTheSameFromOutside(): void
    {
        $withoutToken = $this->dispatch($this->request());
        $withGarbage = $this->dispatch($this->request()->withHeader('Authorization', 'Bearer nope'));

        $expiredRequest = $this->authenticatedRequest('anna');
        $this->clock->advance(999999);
        $withExpired = $this->dispatch($expiredRequest);

        $bodies = array_map(
            static fn (ResponseInterface $response): string => (string) $response->getBody(),
            [$withoutToken, $withGarbage, $withExpired],
        );

        self::assertCount(1, array_unique($bodies));
    }

    public function testTheRefusalCarriesTheSchemeRfc9110AsksFor(): void
    {
        self::assertSame('Bearer', $this->dispatch($this->request())->getHeaderLine('WWW-Authenticate'));
    }

    // -----------------------------------------------------------------

    private function assertRefused(ResponseInterface $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('request.unauthenticated', $body['code']);
    }

    private function middleware(): AuthenticationMiddleware
    {
        return new AuthenticationMiddleware(
            verifier: $this->verifier(),
            denylist: $this->denylist,
            directory: $this->directory,
            problems: new ProblemResponseFactory(new Psr17Factory(), new Psr17Factory()),
            logger: new NullLogger(),
        );
    }

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface|null $inner
     */
    private function dispatch(ServerRequestInterface $request, ?\Closure $inner = null): ResponseInterface
    {
        return $this->middleware()->process(
            $request,
            new CallableHandler(
                $inner ?? static fn (ServerRequestInterface $r): ResponseInterface
                    => (new Psr17Factory())->createResponse(200),
            ),
        );
    }

    private function request(string $permission = 'session.read'): ServerRequestInterface
    {
        return (new Psr17Factory())
            ->createServerRequest('GET', 'https://mail.example.test/api/v1/auth/me')
            ->withAttribute(Dispatcher::ATTRIBUTE_PERMISSION, $permission)
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'test-request-id');
    }

    private function authenticatedRequest(
        string $subject,
        AdminRole $role = AdminRole::Superuser,
    ): ServerRequestInterface {
        return $this->request()->withHeader(
            'Authorization',
            'Bearer ' . $this->issuer()->issue($subject, $role)->value,
        );
    }

    private function issuer(): TokenIssuer
    {
        return new TokenIssuer(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
            3600,
        );
    }

    private function verifier(): TokenVerifier
    {
        return new TokenVerifier(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
        );
    }
}

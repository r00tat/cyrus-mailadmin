<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Middleware;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\DomainScope;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\DomainPolicy;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Http\Middleware\AuthorizationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\NotFoundHandler;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Tests\Support\CallableHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    private const string PATH = '/api/v1/domains/x';

    private Psr17Factory $factory;
    private ProblemResponseFactory $problems;
    private TestHandler $logHandler;
    private AuthorizationMiddleware $middleware;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->problems = new ProblemResponseFactory($this->factory, $this->factory);
        $this->logHandler = new TestHandler();
        $this->middleware = new AuthorizationMiddleware(
            $this->problems,
            new Logger('test', [$this->logHandler]),
        );
    }

    public function testAPublicOperationIsNotEvenAskedWhoIsCalling(): void
    {
        // No actor attribute at all. `POST /auth/token` is exactly this case:
        // authentication waved it through, so there is nobody to check.
        $response = $this->dispatch($this->request(Permission::PublicAccess->value, actor: null));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAPermissionTheRoleHoldsReachesTheHandler(): void
    {
        $response = $this->dispatch($this->request(Permission::AccountWrite->value, $this->domainMaster()));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAPermissionTheRoleDoesNotHoldIsForbidden(): void
    {
        $response = $this->dispatch($this->request(Permission::DomainDelete->value, $this->domainMaster()));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = $this->decode($response);
        self::assertSame('request.forbidden', $body['code']);
        self::assertSame(self::PATH, $body['instance']);
        self::assertSame('req-1', $body['request_id']);
    }

    public function testTheRefusalNamesNeitherThePermissionNorTheRole(): void
    {
        // The client learns that it may not, not what it would have needed. A
        // permission name in the body is a map of the privilege structure.
        $response = $this->dispatch($this->request(Permission::AdminWrite->value, $this->domainMaster()));

        $document = (string) $response->getBody();

        self::assertStringNotContainsString('admin.write', $document);
        self::assertStringNotContainsString('domainmaster', $document);
        self::assertStringNotContainsString('master', $document);
    }

    public function testASuperuserPassesEveryPermissionThereIs(): void
    {
        foreach (Permission::grantable() as $permission) {
            $response = $this->dispatch($this->request($permission->value, ActorContext::superuser('root')));

            self::assertSame(200, $response->getStatusCode(), $permission->value . ' was refused for a superuser.');
        }
    }

    public function testADomainMasterIsRefusedExactlyThePermissionsTheirRoleLacks(): void
    {
        $actor = $this->domainMaster();
        $refused = [];

        foreach (Permission::grantable() as $permission) {
            if ($this->dispatch($this->request($permission->value, $actor))->getStatusCode() === 403) {
                $refused[] = $permission->value;
            }
        }

        self::assertSame([
            'admin.delete',
            'admin.read',
            'admin.write',
            'audit.read',
            'domain.delete',
            'domain.write',
            'list.delete',
            'list.read',
            'list.write',
            'maintenance.read',
            'maintenance.write',
        ], $this->sorted($refused));
    }

    public function testAForbiddenRequestIsLoggedAsAWarningWithTheActorAndThePermission(): void
    {
        $this->dispatch($this->request(Permission::DomainDelete->value, $this->domainMaster()));

        self::assertTrue($this->logHandler->hasWarningRecords());

        $record = $this->logHandler->getRecords()[0];
        self::assertSame(Level::Warning, $record->level);
        self::assertSame('master', $record->context['admin']);
        self::assertSame('domain.delete', $record->context['permission']);
        self::assertSame('someOperation', $record->context['operation_id']);
    }

    // ------------------------------------------------------------------
    // What a handler throws
    // ------------------------------------------------------------------

    public function testAPolicyRefusalFromTheHandlerBecomesTheRightStatusWithoutTheHandlerSayingSo(): void
    {
        $actor = $this->domainMaster();

        $response = $this->dispatch(
            $this->request(Permission::DomainRead->value, $actor),
            new CallableHandler(static function () use ($actor): ResponseInterface {
                DomainPolicy::requireRead($actor, DomainName::fromString('foreign.example'));

                throw new \LogicException('The policy did not refuse.');
            }),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('request.not_found', $this->decode($response)['code']);
    }

    public function testTheHiddenObjectAnswersExactlyWhatAnAbsentOneAnswers(): void
    {
        $request = $this->request(Permission::AccountRead->value, $this->domainMaster());

        $hidden = $this->dispatch($request, CallableHandler::throwing(ResourceNotVisible::inDomain(
            $this->domainMaster(),
            'account',
            DomainName::fromString('foreign.example'),
        )));

        $absent = (new NotFoundHandler($this->problems))->handle($request);

        // Byte for byte: if these two ever differ, the difference is what tells
        // "somebody else's" apart from "not there".
        self::assertSame((string) $absent->getBody(), (string) $hidden->getBody());
        self::assertSame($absent->getStatusCode(), $hidden->getStatusCode());
        self::assertSame($absent->getHeaders(), $hidden->getHeaders());
    }

    public function testHidingAnObjectIsLoggedBelowWarningSoTheWarningStaysWorthReading(): void
    {
        $this->dispatch(
            $this->request(Permission::AccountRead->value, $this->domainMaster()),
            CallableHandler::throwing(ResourceNotVisible::inDomain(
                $this->domainMaster(),
                'account',
                DomainName::fromString('foreign.example'),
            )),
        );

        self::assertFalse($this->logHandler->hasWarningRecords());
        self::assertTrue($this->logHandler->hasInfoRecords());
    }

    public function testAnAccessDeniedFromTheHandlerIsForbidden(): void
    {
        $response = $this->dispatch(
            $this->request(Permission::ProfilePasswordWrite->value, $this->domainMaster()),
            CallableHandler::throwing(AccessDenied::notTheSubject(
                $this->domainMaster(),
                Permission::ProfilePasswordWrite,
                'somebody-else',
            )),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('request.forbidden', $this->decode($response)['code']);
    }

    public function testAnyOtherExceptionIsLeftAloneForTheErrorHandler(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the database is unreachable');

        $this->dispatch(
            $this->request(Permission::AccountRead->value, $this->domainMaster()),
            CallableHandler::throwing(new \RuntimeException('the database is unreachable')),
        );
    }

    // ------------------------------------------------------------------
    // Wiring mistakes fail loudly rather than open
    // ------------------------------------------------------------------

    public function testAnUnroutedRequestIsAProgrammingErrorAndNotAnAllow(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/route-middleware chain/');

        $this->dispatch($this->factory->createServerRequest('GET', self::PATH));
    }

    public function testAPermissionTheCodeDoesNotKnowIsRefusedLoudly(): void
    {
        // The route compiler makes this unreachable in a built installation. If
        // it is ever reached anyway, it must not be a quiet allow.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/not in the Permission enum/');

        $this->dispatch($this->request('domain.wrtie', $this->domainMaster()));
    }

    public function testANonPublicOperationWithoutAnActorIsAProgrammingError(): void
    {
        // Authentication answers 401 first, so this can only mean the chain is
        // wired wrongly — and a 403 here would look like an ordinary refusal.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/without a verified actor/');

        $this->dispatch($this->request(Permission::AccountRead->value, actor: null));
    }

    // ------------------------------------------------------------------

    private function dispatch(ServerRequestInterface $request, ?CallableHandler $handler = null): ResponseInterface
    {
        return $this->middleware->process(
            $request,
            $handler ?? CallableHandler::returning($this->factory->createResponse(200)),
        );
    }

    private function request(string $permission, ?ActorContext $actor = null): ServerRequestInterface
    {
        $request = $this->factory->createServerRequest('GET', self::PATH)
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'req-1')
            ->withAttribute(Dispatcher::ATTRIBUTE_OPERATION_ID, 'someOperation')
            ->withAttribute(Dispatcher::ATTRIBUTE_PERMISSION, $permission);

        return $actor === null
            ? $request
            : $request->withAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR, $actor);
    }

    private function domainMaster(): ActorContext
    {
        return ActorContext::of(
            'master',
            AdminRole::DomainMaster,
            DomainScope::of(DomainName::fromString('own.example')),
        );
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

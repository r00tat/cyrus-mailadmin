<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http;

use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\Middleware\OpenApiValidationMiddleware;
use Mailadmin\Http\NotFoundHandler;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Http\Routing\ContractArtefacts;
use Mailadmin\Http\Routing\DocumentationEndpoint;
use Mailadmin\Http\Routing\Route;
use Mailadmin\Http\Routing\RouteTable;
use Mailadmin\Http\Routing\StaleBuildError;
use Mailadmin\Tests\Unit\Http\Routing\Fixtures\Handler\Widgets\CreateWidgetHandler;
use Mailadmin\Tests\Unit\Http\Routing\Fixtures\Handler\Widgets\ListWidgetsHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

#[CoversClass(Dispatcher::class)]
#[CoversClass(DocumentationEndpoint::class)]
final class DispatcherTest extends TestCase
{
    public function testRoutesToTheHandlerAndHandsItTheOperationPermissionAndPathParameters(): void
    {
        $response = $this->dispatcher()->handle($this->validated('POST', '/api/v1/widgets/42', 'POST /widgets/{widgetId}'));

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('createWidget', $body['operation_id']);
        self::assertSame('widget.write', $body['permission']);
        self::assertSame(['widgetId' => '42'], $body['path_parameters']);
    }

    public function testEachPathParameterIsAlsoAvailableUnderItsOwnName(): void
    {
        $response = $this->dispatcher()->handle($this->validated('POST', '/api/v1/widgets/42', 'POST /widgets/{widgetId}'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('42', $body['widget_id']);
    }

    public function testAnOperationWithoutPathParametersGetsAnEmptyMap(): void
    {
        $response = $this->dispatcher()->handle($this->validated('POST', '/api/v1/widgets', 'POST /widgets'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], $body['path_parameters']);
        self::assertNull($body['widget_id']);
    }

    public function testAnUnknownPathIsAProblemDocumentWithFourOhFour(): void
    {
        $response = $this->dispatcher()->handle(new ServerRequest('GET', '/api/v1/nothing-here'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('request.not_found', $body['code']);
        self::assertSame('/api/v1/nothing-here', $body['instance']);
    }

    public function testAKnownPathWithTheWrongMethodIsFourOhFiveAndSaysWhatIsAllowed(): void
    {
        $response = $this->dispatcher()->handle(new ServerRequest('DELETE', '/api/v1/widgets'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('request.method_not_allowed', $body['code']);
    }

    public function testStaleBuildArtefactsRefuseServiceRatherThanGuess(): void
    {
        $response = $this->dispatcher(artefacts: $this->brokenArtefacts())
            ->handle(new ServerRequest('GET', '/api/v1/widgets'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->getHeaderLine('Retry-After'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('migration.build_stale', $body['code']);
        // The client learns the code, never why the deployment is wrong.
        self::assertStringNotContainsString('composer', (string) $body['detail']);
    }

    public function testARoutedRequestThatSchemaValidationDidNotMarkNeverReachesAHandler(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/without passing schema validation/');

        $this->dispatcher()->handle(new ServerRequest('GET', '/api/v1/widgets'));
    }

    public function testAMarkForADifferentOperationDoesNotCount(): void
    {
        $this->expectException(\LogicException::class);

        $this->dispatcher()->handle($this->validated('GET', '/api/v1/widgets', 'GET /gadgets'));
    }

    public function testTheMountPrefixIsStrippedBeforeMatching(): void
    {
        $response = $this->dispatcher(basePath: '/mailadmin')->handle(
            $this->validated('GET', '/mailadmin/api/v1/widgets', 'GET /widgets'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheDocumentationIndexRedirectsToTheContractAndKeepsTheMountPrefix(): void
    {
        $response = $this->dispatcher(basePath: '/mailadmin', documentation: true)
            ->handle(new ServerRequest('GET', '/mailadmin/api/docs'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/mailadmin/api/docs/openapi.json', $response->getHeaderLine('Location'));
    }

    public function testTheDocumentationServesTheBundledContract(): void
    {
        $response = $this->dispatcher(documentation: true)->handle(new ServerRequest('GET', '/api/docs/openapi.json'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"openapi":"3.0.3"}', (string) $response->getBody());
    }

    public function testWithDocumentationSwitchedOffThePathSimplyDoesNotExist(): void
    {
        $response = $this->dispatcher(documentation: false)->handle(new ServerRequest('GET', '/api/docs'));

        self::assertSame(404, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        // Nothing hints that it could be switched on.
        self::assertSame('request.not_found', $body['code']);
        self::assertStringNotContainsString('docs', (string) $body['detail']);
    }

    private function validated(string $method, string $path, string $mark): ServerRequestInterface
    {
        return (new ServerRequest($method, $path))->withAttribute(OpenApiValidationMiddleware::ATTRIBUTE, $mark);
    }

    private function dispatcher(
        ?ContractArtefacts $artefacts = null,
        ?ApiHandler $handler = null,
        string $basePath = '',
        bool $documentation = false,
    ): Dispatcher {
        $factory = new Psr17Factory();
        $problems = new ProblemResponseFactory($factory, $factory);
        $artefacts ??= $this->artefacts();

        return new Dispatcher(
            artefacts: $artefacts,
            handlers: $this->container($handler),
            problems: $problems,
            notFound: new NotFoundHandler($problems),
            logger: new NullLogger(),
            documentation: new DocumentationEndpoint(
                artefacts: $artefacts,
                responses: $factory,
                streams: $factory,
                enabled: $documentation,
                basePath: $basePath,
            ),
            basePath: $basePath,
            requireValidatedRequests: true,
        );
    }

    private function container(?ApiHandler $handler): ContainerInterface
    {
        return new class ($handler) implements ContainerInterface {
            public function __construct(private readonly ?ApiHandler $handler)
            {
            }

            public function get(string $id): mixed
            {
                if ($this->handler !== null) {
                    return $this->handler;
                }

                return new $id();
            }

            public function has(string $id): bool
            {
                return class_exists($id);
            }
        };
    }

    private function artefacts(): ContractArtefacts
    {
        $table = new RouteTable(
            specChecksum: 'spec',
            sourceChecksum: 'source',
            basePath: '/api/v1',
            routes: [
                new Route('GET', '/api/v1/widgets', '/widgets', 'listWidgets', ListWidgetsHandler::class, 'widget.read', 'widgets'),
                new Route('POST', '/api/v1/widgets', '/widgets', 'createWidget', CreateWidgetHandler::class, 'widget.write', 'widgets'),
                new Route('POST', '/api/v1/widgets/{widgetId}', '/widgets/{widgetId}', 'createWidget', CreateWidgetHandler::class, 'widget.write', 'widgets'),
            ],
            generatedAt: '2026-07-27T00:00:00Z',
        );

        return new class ($table) implements ContractArtefacts {
            public function __construct(private readonly RouteTable $table)
            {
            }

            public function routeTable(): RouteTable
            {
                return $this->table;
            }

            public function specification(): \cebe\openapi\spec\OpenApi
            {
                throw new \LogicException('The dispatcher does not parse the contract.');
            }

            public function bundledJson(): string
            {
                return '{"openapi":"3.0.3"}';
            }
        };
    }

    private function brokenArtefacts(): ContractArtefacts
    {
        return new class () implements ContractArtefacts {
            public function routeTable(): RouteTable
            {
                throw StaleBuildError::checksumMismatch('openapi.bundled.yaml');
            }

            public function specification(): \cebe\openapi\spec\OpenApi
            {
                throw StaleBuildError::checksumMismatch('openapi.bundled.yaml');
            }

            public function bundledJson(): string
            {
                throw StaleBuildError::checksumMismatch('openapi.bundled.yaml');
            }
        };
    }
}

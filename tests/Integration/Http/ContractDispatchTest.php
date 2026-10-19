<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Http;

use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Middleware\ErrorHandlerMiddleware;
use Mailadmin\Http\Middleware\JsonBodyMiddleware;
use Mailadmin\Http\Middleware\OpenApiValidationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Middleware\SecurityHeadersMiddleware;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Http\NotFoundHandler;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Http\Routing\ContractBuild;
use Mailadmin\Http\Routing\DocumentationEndpoint;
use Mailadmin\Http\Routing\RouteCompiler;
use Mailadmin\Http\Routing\SpecificationBundler;
use Mailadmin\Log\RequestIdProcessor;
use Mailadmin\Runtime\CacheDirectory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * The whole chain over a contract built from source: bundle, lint, compile,
 * validate, route, answer.
 *
 * Nothing is stubbed except the logger. That is the point — the real bundler and
 * the real validator are what these tests are about, and a double of the
 * artefacts would prove nothing about the pipeline that produces them.
 *
 * The contract lives in `Fixtures/contract/` and describes an invented resource,
 * so that it stays valid when the A packages add the real endpoints.
 */
#[CoversClass(Dispatcher::class)]
#[CoversClass(OpenApiValidationMiddleware::class)]
#[CoversClass(ContractBuild::class)]
final class ContractDispatchTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/mailadmin-contract-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDirectory, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->cacheDirectory);
    }

    public function testAValidRequestReachesTheHandler(): void
    {
        $response = $this->handle(new ServerRequest('GET', '/api/v1/widgets?per_page=10'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testARequestBodyThatDoesNotMatchTheSchemaIsRejectedWithFourTwentyTwoAndAFieldMap(): void
    {
        $response = $this->handle($this->json('POST', '/api/v1/widgets', ['size' => 99], token: true));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = $this->body($response);

        self::assertSame('request.validation_failed', $body['code']);
        self::assertSame(['body.name' => 'required'], $body['errors']);
    }

    public function testTheFieldMapNamesTheFieldAndTheConstraintItFailed(): void
    {
        $response = $this->handle($this->json('POST', '/api/v1/widgets', ['name' => 'a', 'size' => 99], token: true));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['body.size' => 'maximum'], $this->body($response)['errors']);
    }

    public function testAWrongTypeIsReportedAsTheTypeConstraint(): void
    {
        $response = $this->handle($this->json('POST', '/api/v1/widgets', ['name' => 17], token: true));

        self::assertSame(['body.name' => 'type'], $this->body($response)['errors']);
    }

    public function testAValueOutsideAnEnumerationIsReportedAsTheEnumConstraint(): void
    {
        $response = $this->handle($this->json('POST', '/api/v1/widgets', ['name' => 'a', 'colour' => 'mauve'], token: true));

        self::assertSame(['body.colour' => 'enum'], $this->body($response)['errors']);
    }

    public function testNoSubmittedValueIsEverEchoedBack(): void
    {
        $secret = 'hunter2-should-never-appear';

        $response = $this->handle($this->json('POST', '/api/v1/widgets', ['name' => $secret, 'size' => 99], token: true));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringNotContainsString($secret, (string) $response->getBody());
    }

    public function testAQueryParameterOutsideItsRangeIsRejectedBeforeAnyHandlerRuns(): void
    {
        $response = $this->handle(new ServerRequest('GET', '/api/v1/widgets?per_page=9999'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['query.per_page' => 'maximum'], $this->body($response)['errors']);
    }

    public function testAnOperationThatRequiresATokenIsFourOhOneWithoutOne(): void
    {
        $response = $this->handle($this->json('POST', '/api/v1/widgets', ['name' => 'ok']));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('request.unauthenticated', $this->body($response)['code']);
    }

    public function testAValidRequestWithABodyReachesTheHandler(): void
    {
        $response = $this->handle($this->json('POST', '/api/v1/widgets', ['name' => 'ok', 'size' => 3], token: true));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('createWidget', $this->body($response)['operation_id']);
    }

    public function testAnUnknownPathIsAProblemDocumentAndNotAValidationFailure(): void
    {
        $response = $this->handle(new ServerRequest('GET', '/api/v1/gadgets'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('request.not_found', $this->body($response)['code']);
    }

    public function testMalformedJsonIsRejectedBeforeSchemaValidation(): void
    {
        $request = (new ServerRequest('POST', '/api/v1/widgets'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create('{"name":'));

        $response = $this->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('request.malformed_json', $this->body($response)['code']);
    }

    public function testTheBuildIsIdempotentAndTheChecksumIsStable(): void
    {
        $first = $this->build()->build();
        $second = $this->build()->build();

        self::assertSame($first->specChecksum, $second->specChecksum);
        self::assertSame($first->sourceChecksum, $second->sourceChecksum);
    }

    public function testAnArtefactThatNoLongerMatchesTheContractRefusesServiceWhenRebuildingIsNotAllowed(): void
    {
        $this->build(mayRebuild: true)->build();

        file_put_contents($this->cacheDirectory . '/' . ContractBuild::BUNDLED_YAML, "openapi: 3.0.3\n");

        $response = $this->handle(new ServerRequest('GET', '/api/v1/widgets'), mayRebuild: false);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('migration.build_stale', $this->body($response)['code']);
    }

    public function testInDevelopmentAnEditedContractIsPickedUpWithoutARebuildCommand(): void
    {
        $build = $this->build(mayRebuild: true);
        $build->build();

        // Simulate a deployment that shipped an older route table.
        file_put_contents($this->cacheDirectory . '/' . ContractBuild::BUNDLED_YAML, "openapi: 3.0.3\n");

        $response = $this->handle(new ServerRequest('GET', '/api/v1/widgets'), mayRebuild: true);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(string $method, string $path, array $payload, bool $token = false): ServerRequest
    {
        $request = (new ServerRequest($method, $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode($payload, JSON_THROW_ON_ERROR)));

        return $token ? $request->withHeader('Authorization', 'Bearer test-token') : $request;
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

    private function handle(ServerRequest $request, bool $mayRebuild = true): ResponseInterface
    {
        $factory = new Psr17Factory();
        $problems = new ProblemResponseFactory($factory, $factory);
        $logger = new NullLogger();
        $artefacts = $this->build(mayRebuild: $mayRebuild);

        $dispatcher = new Dispatcher(
            artefacts: $artefacts,
            handlers: $this->container(),
            problems: $problems,
            notFound: new NotFoundHandler($problems),
            logger: $logger,
            documentation: new DocumentationEndpoint($artefacts, $factory, $factory, enabled: false, basePath: ''),
            basePath: '',
            requireValidatedRequests: true,
        );

        $chain = new MiddlewareDispatcher(
            $dispatcher,
            new RequestIdMiddleware(new RequestIdProcessor()),
            new SecurityHeadersMiddleware(),
            new ErrorHandlerMiddleware($logger, $problems),
            new JsonBodyMiddleware($problems),
            new OpenApiValidationMiddleware($artefacts, $problems, $logger, basePath: '', validateResponses: true),
        );

        return $chain->handle($request);
    }

    private function build(bool $mayRebuild = true): ContractBuild
    {
        return new ContractBuild(
            bundler: new SpecificationBundler(__DIR__ . '/Fixtures/contract'),
            compiler: new RouteCompiler(
                dirname(__DIR__, 2) . '/Unit/Http/Routing/Fixtures/Handler',
                'Mailadmin\\Tests\\Unit\\Http\\Routing\\Fixtures\\Handler',
            ),
            cache: new CacheDirectory($this->cacheDirectory),
            mayRebuild: $mayRebuild,
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
}

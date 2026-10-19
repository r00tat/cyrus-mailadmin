<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Middleware;

use Mailadmin\Http\Middleware\JsonBodyMiddleware;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Tests\Support\CallableHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(JsonBodyMiddleware::class)]
#[CoversClass(ProblemDetails::class)]
#[CoversClass(ProblemResponseFactory::class)]
#[CoversClass(ErrorCode::class)]
final class JsonBodyMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;
    private JsonBodyMiddleware $middleware;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->middleware = new JsonBodyMiddleware(
            new ProblemResponseFactory($this->factory, $this->factory),
        );
    }

    public function testAJsonObjectBecomesTheParsedBody(): void
    {
        $parsed = null;

        $response = $this->middleware->process(
            $this->request('POST', '{"domain":"example.test","quota":1024}'),
            new CallableHandler(function (ServerRequestInterface $request) use (&$parsed): ResponseInterface {
                $parsed = $request->getParsedBody();

                return $this->factory->createResponse(201);
            }),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['domain' => 'example.test', 'quota' => 1024], $parsed);
    }

    public function testAJsonArrayIsAccepted(): void
    {
        $parsed = null;

        $this->middleware->process(
            $this->request('PUT', '[{"address":"a@example.test"}]'),
            new CallableHandler(function (ServerRequestInterface $request) use (&$parsed): ResponseInterface {
                $parsed = $request->getParsedBody();

                return $this->factory->createResponse();
            }),
        );

        self::assertSame([['address' => 'a@example.test']], $parsed);
    }

    public function testAStructuredJsonSuffixContentTypeIsAccepted(): void
    {
        $parsed = null;

        $this->middleware->process(
            $this->request('PATCH', '{"quota":2048}', 'application/merge-patch+json'),
            new CallableHandler(function (ServerRequestInterface $request) use (&$parsed): ResponseInterface {
                $parsed = $request->getParsedBody();

                return $this->factory->createResponse();
            }),
        );

        self::assertSame(['quota' => 2048], $parsed);
    }

    public function testTheCharsetParameterIsIgnored(): void
    {
        $parsed = null;

        $this->middleware->process(
            $this->request('POST', '{"a":1}', 'application/json; charset=utf-8'),
            new CallableHandler(function (ServerRequestInterface $request) use (&$parsed): ResponseInterface {
                $parsed = $request->getParsedBody();

                return $this->factory->createResponse();
            }),
        );

        self::assertSame(['a' => 1], $parsed);
    }

    public function testAnEmptyBodyPassesThroughUntouched(): void
    {
        $reached = false;

        $response = $this->middleware->process(
            $this->request('POST', ''),
            new CallableHandler(function (ServerRequestInterface $request) use (&$reached): ResponseInterface {
                $reached = true;
                self::assertNull($request->getParsedBody());

                return $this->factory->createResponse(204);
            }),
        );

        self::assertTrue($reached);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testAGetRequestIsNotInspected(): void
    {
        $reached = false;

        $this->middleware->process(
            $this->request('GET', 'not json at all', 'text/plain'),
            new CallableHandler(function () use (&$reached): ResponseInterface {
                $reached = true;

                return $this->factory->createResponse();
            }),
        );

        self::assertTrue($reached);
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedJsonIsRejectedWithAStableCode(string $body): void
    {
        $response = $this->middleware->process(
            $this->request('POST', $body),
            CallableHandler::throwing(new \LogicException('The handler must not be reached.')),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $document = $this->decode($response);
        self::assertSame(ErrorCode::MALFORMED_JSON, $document['code']);
        self::assertSame(400, $document['status']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'truncated' => ['{"domain":'];
        yield 'single quotes' => ["{'domain':'example.test'}"];
        yield 'trailing comma' => ['{"a":1,}'];
        yield 'bare string' => ['"example.test"'];
        yield 'bare number' => ['42'];
        yield 'bare null' => ['null'];
        yield 'not json' => ['<xml/>'];
    }

    public function testABodyWithTheWrongContentTypeIsRejectedWith415(): void
    {
        $response = $this->middleware->process(
            $this->request('POST', 'domain=example.test', 'application/x-www-form-urlencoded'),
            CallableHandler::throwing(new \LogicException('The handler must not be reached.')),
        );

        self::assertSame(415, $response->getStatusCode());
        self::assertSame(ErrorCode::UNSUPPORTED_MEDIA_TYPE, $this->decode($response)['code']);
    }

    public function testABodyWithoutAContentTypeIsRejected(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'https://mail.example.test/api/v1/domains')
            ->withBody($this->factory->createStream('{"a":1}'));

        $response = $this->middleware->process(
            $request,
            CallableHandler::throwing(new \LogicException('The handler must not be reached.')),
        );

        self::assertSame(415, $response->getStatusCode());
    }

    public function testTheProblemDocumentCarriesTheRequestIdSoItCanBeFoundInTheLog(): void
    {
        $request = $this->request('POST', '{oops')
            ->withAttribute(\Mailadmin\Http\Middleware\RequestIdMiddleware::ATTRIBUTE, 'abc123def456');

        $response = $this->middleware->process(
            $request,
            CallableHandler::throwing(new \LogicException('The handler must not be reached.')),
        );

        self::assertSame('abc123def456', $this->decode($response)['request_id']);
    }

    public function testExcessiveNestingIsRejected(): void
    {
        $body = str_repeat('[', 100) . str_repeat(']', 100);

        $response = $this->middleware->process(
            $this->request('POST', $body),
            CallableHandler::throwing(new \LogicException('The handler must not be reached.')),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    private function request(
        string $method,
        string $body,
        string $contentType = 'application/json',
    ): ServerRequestInterface {
        return $this->factory
            ->createServerRequest($method, 'https://mail.example.test/api/v1/domains')
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->factory->createStream($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

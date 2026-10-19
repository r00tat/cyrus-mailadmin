<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Http;

use Mailadmin\Http\Middleware\ErrorHandlerMiddleware;
use Mailadmin\Http\Middleware\JsonBodyMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Middleware\SecurityHeadersMiddleware;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Http\NotFoundHandler;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Http\SecurityHeaders;
use Mailadmin\Log\RequestIdProcessor;
use Mailadmin\Log\SecretMaskingProcessor;
use Mailadmin\Tests\Support\CallableHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The whole middleware chain, wired as config/di/core.php wires it.
 *
 * This is where F1's definition of done is checked end to end: a deliberate
 * failure produces a log entry and a generic `problem+json` with no exception
 * detail, and every security header from architecture 5.7 is present — including
 * on the error response, which is the case the middleware order is arranged for.
 */
#[CoversNothing]
final class ErrorHandlingTest extends TestCase
{
    private Psr17Factory $factory;
    private TestHandler $logHandler;
    private RequestIdProcessor $requestIdProcessor;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->logHandler = new TestHandler();
        $this->requestIdProcessor = new RequestIdProcessor();
    }

    public function testADeliberateFailureYieldsAGenericProblemDocument(): void
    {
        $response = $this->chain(CallableHandler::throwing(
            new \RuntimeException('cyrus said NO on setquota for admin@example.test'),
        ))->handle($this->request());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $document = $this->decode($response);
        self::assertSame(ErrorCode::INTERNAL_ERROR, $document['code']);
        self::assertSame(500, $document['status']);
    }

    public function testTheFailureResponseLeaksNothingAboutTheException(): void
    {
        $body = (string) $this->chain(CallableHandler::throwing(
            new \RuntimeException('cyrus said NO on setquota for admin@example.test'),
        ))->handle($this->request())->getBody();

        self::assertStringNotContainsString('cyrus', $body);
        self::assertStringNotContainsString('setquota', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('Mailadmin\\', $body);
        self::assertStringNotContainsString(__FILE__, $body);
    }

    public function testTheFailureIsLoggedOnceWithTheDetailAndTheRequestId(): void
    {
        $response = $this->chain(CallableHandler::throwing(
            new \RuntimeException('cyrus said NO on setquota'),
        ))->handle($this->request());

        self::assertTrue($this->logHandler->hasErrorRecords());

        $records = $this->logHandler->getRecords();
        self::assertCount(1, $records);

        $record = $records[0];
        self::assertSame('cyrus said NO on setquota', $record->context['exception_message']);

        // The log line and the response point at each other.
        self::assertSame(
            $response->getHeaderLine(RequestIdMiddleware::HEADER),
            $record->extra['request_id'],
        );
        self::assertSame(
            $this->decode($response)['request_id'],
            $record->extra['request_id'],
        );
    }

    /**
     * The reason SecurityHeaders sits outside ErrorHandler. A response the error
     * handler builds never passes back through anything nested inside it, so with
     * the reverse order every 500 would ship without a Content-Security-Policy —
     * and a 500 in a browser is exactly the response most likely to be rendered.
     */
    public function testTheErrorResponseCarriesEverySecurityHeader(): void
    {
        $response = $this->chain(CallableHandler::throwing(new \RuntimeException('boom')))
            ->handle($this->request());

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            self::assertSame($value, $response->getHeaderLine($name), $name . ' is missing on the error response.');
        }

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $response->getHeaderLine(RequestIdMiddleware::HEADER));
    }

    public function testASuccessfulResponseCarriesTheHeadersAndTheRequestId(): void
    {
        $handler = new CallableHandler(function (ServerRequestInterface $request): ResponseInterface {
            return $this->factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream('{"status":"ok"}'));
        });

        $response = $this->chain($handler)->handle($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            $response->getHeaderLine(RequestIdMiddleware::HEADER),
        );
        self::assertSame(SecurityHeaders::CONTENT_SECURITY_POLICY, $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($this->logHandler->hasErrorRecords());
    }

    /**
     * F1 has no route table yet — F2 supplies it. Until then the chain ends in a
     * well-formed 404, which is still a well-formed JSON answer with a request id.
     */
    public function testWithoutARouteTableTheChainEndsInAWellFormedNotFound(): void
    {
        $response = $this->chain()->handle($this->request());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $document = $this->decode($response);
        self::assertSame(ErrorCode::NOT_FOUND, $document['code']);
        self::assertSame('/api/v1/domains', $document['instance']);
        self::assertSame($response->getHeaderLine(RequestIdMiddleware::HEADER), $document['request_id']);
    }

    public function testAMalformedBodyIsRejectedBehindTheSecurityHeaders(): void
    {
        $request = $this->request('POST')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{"domain":'));

        $response = $this->chain(CallableHandler::throwing(
            new \LogicException('The chain must not reach the innermost handler.'),
        ))->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ErrorCode::MALFORMED_JSON, $this->decode($response)['code']);
        self::assertSame(SecurityHeaders::CONTENT_SECURITY_POLICY, $response->getHeaderLine('Content-Security-Policy'));
        self::assertNotSame('', $response->getHeaderLine(RequestIdMiddleware::HEADER));
        self::assertFalse($this->logHandler->hasErrorRecords(), 'A client mistake is not an application error.');
    }

    /**
     * A secret must not appear in the log even when the field carrying it is
     * spelled by whoever wrote the log call.
     */
    public function testTheChainLoggerMasksSecrets(): void
    {
        $logger = $this->logger();
        $logger->error('Authentication failed.', [
            'username' => 'admin',
            'password' => 'hunter2',
            'password_hash' => '$2y$10$abcdefghijklmnopqrstuv',
        ]);

        $context = $this->logHandler->getRecords()[0]->context;

        self::assertSame('admin', $context['username']);
        self::assertSame(SecretMaskingProcessor::REDACTED, $context['password']);
        self::assertSame(SecretMaskingProcessor::REDACTED, $context['password_hash']);
    }

    private function chain(?RequestHandlerInterface $innermost = null): MiddlewareDispatcher
    {
        $problems = new ProblemResponseFactory($this->factory, $this->factory);

        return new MiddlewareDispatcher(
            $innermost ?? new NotFoundHandler($problems),
            new RequestIdMiddleware($this->requestIdProcessor),
            new SecurityHeadersMiddleware(),
            new ErrorHandlerMiddleware($this->logger(), $problems),
            new JsonBodyMiddleware($problems),
        );
    }

    private function logger(): Logger
    {
        $logger = new Logger('mailadmin', [$this->logHandler]);
        $logger->pushProcessor(new SecretMaskingProcessor());
        $logger->pushProcessor($this->requestIdProcessor);

        return $logger;
    }

    private function request(string $method = 'GET'): ServerRequestInterface
    {
        return $this->factory->createServerRequest($method, 'https://mail.example.test/api/v1/domains');
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

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Middleware;

use Mailadmin\Http\Middleware\ErrorHandlerMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ErrorCode;
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

#[CoversClass(ErrorHandlerMiddleware::class)]
final class ErrorHandlerMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;
    private TestHandler $logHandler;
    private ErrorHandlerMiddleware $middleware;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->logHandler = new TestHandler();
        $this->middleware = new ErrorHandlerMiddleware(
            new Logger('test', [$this->logHandler]),
            new ProblemResponseFactory($this->factory, $this->factory),
        );
    }

    public function testASuccessfulResponsePassesThroughUnchanged(): void
    {
        $expected = $this->factory->createResponse(201);

        $response = $this->middleware->process($this->request(), CallableHandler::returning($expected));

        self::assertSame($expected, $response);
        self::assertFalse($this->logHandler->hasErrorRecords());
    }

    public function testAnExceptionBecomesAGenericProblemDocument(): void
    {
        $response = $this->handle(new \RuntimeException('connection to cyrus refused'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $document = $this->decode($response);
        self::assertSame(ErrorCode::INTERNAL_ERROR, $document['code']);
        self::assertSame(500, $document['status']);
        self::assertSame('Internal Server Error', $document['title']);
        self::assertSame('about:blank', $document['type']);
    }

    /**
     * F1, definition of done: no exception detail reaches the client. An error page
     * naming an internal class tells an attacker how the application is built.
     */
    public function testNothingAboutTheExceptionReachesTheClient(): void
    {
        $exception = new \DomainException('SQLSTATE[28000] access denied for user mailadmin_app');
        $body = (string) $this->handle($exception)->getBody();

        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString('access denied', $body);
        self::assertStringNotContainsString('mailadmin_app', $body);
        self::assertStringNotContainsString('DomainException', $body);
        self::assertStringNotContainsString(__FILE__, $body);
        self::assertStringNotContainsString('#0', $body);
    }

    public function testTheProblemDocumentPointsAtTheRequestAndTheLogEntry(): void
    {
        $request = $this->request()->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'abc123def456');

        $response = $this->middleware->process(
            $request,
            CallableHandler::throwing(new \RuntimeException('boom')),
        );

        $document = $this->decode($response);
        self::assertSame('/api/v1/domains', $document['instance']);
        self::assertSame('abc123def456', $document['request_id']);
    }

    public function testTheLogEntryCarriesWhatTheResponseWithholds(): void
    {
        $exception = new \RuntimeException('connection to cyrus refused');

        $this->middleware->process(
            $this->request('DELETE')->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'abc123def456'),
            CallableHandler::throwing($exception),
        );

        self::assertTrue($this->logHandler->hasErrorRecords());

        $records = $this->logHandler->getRecords();
        self::assertCount(1, $records);

        $record = $records[0];
        self::assertSame(Level::Error, $record->level);

        $context = $record->context;
        self::assertSame(\RuntimeException::class, $context['exception_class']);
        self::assertSame('connection to cyrus refused', $context['exception_message']);
        self::assertSame('DELETE', $context['http_method']);
        self::assertSame('/api/v1/domains', $context['http_path']);
        self::assertIsString($context['exception_trace']);
        self::assertNotSame('', $context['exception_trace']);
    }

    public function testAChainedExceptionIsLoggedTogetherWithItsCause(): void
    {
        $cause = new \PDOException('SQLSTATE[HY000] server has gone away');
        $exception = new \RuntimeException('could not load the domain', 0, $cause);

        $this->middleware->process($this->request(), CallableHandler::throwing($exception));

        $context = $this->logHandler->getRecords()[0]->context;
        self::assertSame(\PDOException::class, $context['previous_class']);
        self::assertSame('SQLSTATE[HY000] server has gone away', $context['previous_message']);
    }

    public function testAnErrorRatherThanAnExceptionIsAlsoCaught(): void
    {
        // A TypeError from a bad mapping must not reach the client either.
        $response = $this->handle(new \TypeError('argument #1 must be string, null given'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('TypeError', (string) $response->getBody());
    }

    private function handle(\Throwable $exception): ResponseInterface
    {
        return $this->middleware->process($this->request(), CallableHandler::throwing($exception));
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

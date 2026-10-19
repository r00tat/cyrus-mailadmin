<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Middleware;

use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Log\RequestIdProcessor;
use Mailadmin\Tests\Support\CallableHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(RequestIdMiddleware::class)]
#[CoversClass(RequestIdProcessor::class)]
final class RequestIdMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;
    private RequestIdProcessor $processor;
    private RequestIdMiddleware $middleware;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->processor = new RequestIdProcessor();
        $this->middleware = new RequestIdMiddleware($this->processor);
    }

    public function testAnIdIsGeneratedAndAppearsInTheResponseHeader(): void
    {
        $response = $this->middleware->process(
            $this->request(),
            CallableHandler::returning($this->factory->createResponse()),
        );

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->getHeaderLine('X-Request-Id'));
    }

    public function testTheSameIdIsVisibleToTheHandlerAndInTheResponse(): void
    {
        $seen = null;

        $response = $this->middleware->process(
            $this->request(),
            new CallableHandler(function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

                return $this->factory->createResponse();
            }),
        );

        self::assertIsString($seen);
        self::assertSame($seen, $response->getHeaderLine('X-Request-Id'));
    }

    public function testTwoRequestsGetDifferentIds(): void
    {
        $first = $this->middleware->process($this->request(), CallableHandler::returning($this->factory->createResponse()));
        $second = $this->middleware->process($this->request(), CallableHandler::returning($this->factory->createResponse()));

        self::assertNotSame($first->getHeaderLine('X-Request-Id'), $second->getHeaderLine('X-Request-Id'));
    }

    public function testAWellFormedIdFromTheClientIsReusedForCorrelation(): void
    {
        $supplied = '0191f3a4-5b6c-7d8e-9f01-234567890abc';

        $response = $this->middleware->process(
            $this->request()->withHeader('X-Request-Id', $supplied),
            CallableHandler::returning($this->factory->createResponse()),
        );

        self::assertSame($supplied, $response->getHeaderLine('X-Request-Id'));
    }

    /**
     * A request header is not a trustworthy source. Newlines would let a client
     * forge log lines; unbounded length would let it bloat every record.
     */
    #[DataProvider('rejectedIds')]
    public function testAnUnacceptableIdFromTheClientIsReplaced(string $supplied): void
    {
        $response = $this->middleware->process(
            $this->request()->withHeader('X-Request-Id', $supplied),
            CallableHandler::returning($this->factory->createResponse()),
        );

        $actual = $response->getHeaderLine('X-Request-Id');

        self::assertNotSame($supplied, $actual);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $actual);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedIds(): iterable
    {
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'space' => ['id with space'];
        yield 'semicolon' => ['id;injected'];
        yield 'json fragment' => ['{"level":"emergency"}'];
        yield 'quote' => ['id"quoted'];
        yield 'slash' => ['../../etc/passwd'];
    }

    public function testTheLoggerLearnsTheIdWhileTheRequestRunsAndForgetsItAfterwards(): void
    {
        $seenByLogger = null;

        $this->middleware->process(
            $this->request(),
            new CallableHandler(function () use (&$seenByLogger): ResponseInterface {
                $seenByLogger = $this->processor->get();

                return $this->factory->createResponse();
            }),
        );

        self::assertIsString($seenByLogger);
        self::assertNull($this->processor->get(), 'The id must not leak into the next request.');
    }

    public function testTheIdIsClearedEvenWhenTheHandlerThrows(): void
    {
        try {
            $this->middleware->process(
                $this->request(),
                CallableHandler::throwing(new \RuntimeException('boom')),
            );
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNull($this->processor->get());
    }

    private function request(): ServerRequestInterface
    {
        return $this->factory->createServerRequest('GET', 'https://mail.example.test/api/v1/domains');
    }
}

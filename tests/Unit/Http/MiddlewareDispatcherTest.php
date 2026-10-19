<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http;

use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Tests\Support\AttributeMiddleware;
use Mailadmin\Tests\Support\CallableHandler;
use Mailadmin\Tests\Support\CallTrace;
use Mailadmin\Tests\Support\RecordingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(MiddlewareDispatcher::class)]
final class MiddlewareDispatcherTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testMiddlewareRunsOutsideInAndUnwindsInsideOut(): void
    {
        $trace = new CallTrace();
        $dispatcher = new MiddlewareDispatcher(
            CallableHandler::returning($this->response(), $trace),
            new RecordingMiddleware('first', $trace),
            new RecordingMiddleware('second', $trace),
            new RecordingMiddleware('third', $trace),
        );

        $dispatcher->handle($this->request());

        self::assertSame([
            'first:before',
            'second:before',
            'third:before',
            'innermost',
            'third:after',
            'second:after',
            'first:after',
        ], $trace->entries());
    }

    public function testWithoutMiddlewareTheInnermostHandlerAnswersDirectly(): void
    {
        $expected = $this->response(204);
        $dispatcher = new MiddlewareDispatcher(CallableHandler::returning($expected));

        self::assertSame($expected, $dispatcher->handle($this->request()));
    }

    public function testAShortCircuitStopsTheChainAndSkipsTheInnermostHandler(): void
    {
        $trace = new CallTrace();
        $shortCircuit = $this->response(401);

        $dispatcher = new MiddlewareDispatcher(
            CallableHandler::returning($this->response(), $trace),
            new RecordingMiddleware('outer', $trace),
            new RecordingMiddleware('gate', $trace, shortCircuit: $shortCircuit),
            new RecordingMiddleware('never', $trace),
        );

        $response = $dispatcher->handle($this->request());

        self::assertSame($shortCircuit, $response);
        self::assertSame([
            'outer:before',
            'gate:before',
            'gate:short-circuit',
            'outer:after',
        ], $trace->entries());
        self::assertNotContains('never:before', $trace->entries());
        self::assertNotContains('innermost', $trace->entries());
    }

    public function testAnExceptionFromTheInnermostHandlerPassesThroughUnchanged(): void
    {
        $trace = new CallTrace();
        $boom = new \RuntimeException('boom');

        $dispatcher = new MiddlewareDispatcher(
            CallableHandler::throwing($boom),
            new RecordingMiddleware('outer', $trace),
        );

        try {
            $dispatcher->handle($this->request());
            self::fail('Expected the exception to propagate.');
        } catch (\RuntimeException $caught) {
            self::assertSame($boom, $caught);
        }

        // The unwinding half of the outer middleware never runs — which is why an
        // error handler has to catch rather than rely on `finally`.
        self::assertSame(['outer:before'], $trace->entries());
    }

    public function testAnExceptionFromAMiddlewareIsNotSwallowed(): void
    {
        $trace = new CallTrace();
        $boom = new \LogicException('bad wiring');

        $dispatcher = new MiddlewareDispatcher(
            CallableHandler::returning($this->response(), $trace),
            new RecordingMiddleware('outer', $trace),
            new RecordingMiddleware('broken', $trace, throw: $boom),
        );

        $this->expectExceptionObject($boom);

        $dispatcher->handle($this->request());
    }

    public function testTheSameDispatcherCanServeSeveralRequests(): void
    {
        // The dispatcher clones itself instead of advancing a cursor. Without
        // that, the second call would resume in the middle of the chain — which
        // matters for tests today and for any long-running SAPI later.
        $trace = new CallTrace();
        $dispatcher = new MiddlewareDispatcher(
            CallableHandler::returning($this->response(), $trace),
            new RecordingMiddleware('first', $trace),
            new RecordingMiddleware('second', $trace),
        );

        $dispatcher->handle($this->request());
        $dispatcher->handle($this->request());

        self::assertSame([
            'first:before', 'second:before', 'innermost', 'second:after', 'first:after',
            'first:before', 'second:before', 'innermost', 'second:after', 'first:after',
        ], $trace->entries());
    }

    public function testTheRequestPassedOnByAMiddlewareReachesTheInnermostHandler(): void
    {
        $seen = null;

        $dispatcher = new MiddlewareDispatcher(
            new CallableHandler(function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request->getAttribute('marker');

                return $this->response();
            }),
            new AttributeMiddleware('marker', 'set-by-middleware'),
        );

        $dispatcher->handle($this->request());

        self::assertSame('set-by-middleware', $seen);
    }

    private function request(string $method = 'GET', string $path = '/api/v1/domains'): ServerRequestInterface
    {
        return $this->factory->createServerRequest($method, 'https://mail.example.test' . $path);
    }

    private function response(int $status = 200): ResponseInterface
    {
        return $this->factory->createResponse($status);
    }
}

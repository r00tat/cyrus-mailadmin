<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The PSR-15 middleware runner — written here rather than pulled in, which is
 * what architecture 3.1 means by "a lean dispatcher of our own, about 40 lines".
 *
 * The dispatcher is itself a `RequestHandlerInterface`: each middleware receives
 * a *copy* advanced by one position as its `$handler`. Cloning instead of
 * mutating a cursor is not decoration — without it, a second `handle()` call on
 * the same instance would resume in the middle of the chain. That matters for
 * tests today and for any long-running SAPI later.
 */
final class MiddlewareDispatcher implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private readonly array $middleware;

    private int $position = 0;

    /**
     * @param RequestHandlerInterface $innermost handles the request once every middleware has run
     */
    public function __construct(
        private readonly RequestHandlerInterface $innermost,
        MiddlewareInterface ...$middleware,
    ) {
        $this->middleware = array_values($middleware);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $current = $this->middleware[$this->position] ?? null;

        if ($current === null) {
            return $this->innermost->handle($request);
        }

        $next = clone $this;
        $next->position = $this->position + 1;

        return $current->process($request, $next);
    }
}

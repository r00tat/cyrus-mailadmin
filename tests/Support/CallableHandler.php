<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The innermost handler, supplied as a closure.
 */
final readonly class CallableHandler implements RequestHandlerInterface
{
    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function __construct(private \Closure $handler)
    {
    }

    public static function returning(ResponseInterface $response, ?CallTrace $trace = null): self
    {
        return new self(static function (ServerRequestInterface $request) use ($response, $trace): ResponseInterface {
            $trace?->add('innermost');

            return $response;
        });
    }

    public static function throwing(\Throwable $exception): self
    {
        return new self(static function (ServerRequestInterface $request) use ($exception): ResponseInterface {
            throw $exception;
        });
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handler)($request);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A middleware that notes when it was entered and left, and can either continue
 * the chain, short-circuit it or throw.
 */
final readonly class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $name,
        private CallTrace $trace,
        private ?ResponseInterface $shortCircuit = null,
        private ?\Throwable $throw = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->trace->add($this->name . ':before');

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->shortCircuit !== null) {
            $this->trace->add($this->name . ':short-circuit');

            return $this->shortCircuit;
        }

        $response = $handler->handle($request);

        $this->trace->add($this->name . ':after');

        return $response;
    }
}

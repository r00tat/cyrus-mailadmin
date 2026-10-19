<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sets a request attribute and passes the modified request down the chain — the
 * mechanism every real middleware uses to hand something on.
 */
final readonly class AttributeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $name,
        private mixed $value,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute($this->name, $this->value));
    }
}

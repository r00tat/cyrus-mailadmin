<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Produces the `404` document.
 *
 * The innermost handler of the chain is the Dispatcher, compiled from the OpenAPI
 * contract. This class is what it falls back to when no route matched — the one
 * response that cannot come from a contract operation, because by definition
 * there is none.
 */
final readonly class NotFoundHandler implements RequestHandlerInterface
{
    public function __construct(private ProblemResponseFactory $problems)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return $this->problems->create(ProblemDetails::notFound(
            instance: $request->getUri()->getPath(),
            requestId: is_string($requestId) ? $requestId : null,
        ));
    }
}

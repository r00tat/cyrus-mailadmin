<?php

declare(strict_types=1);

namespace Mailadmin\Http\Middleware;

use Mailadmin\Http\SecurityHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies the headers from architecture 5.7 to every response.
 *
 * The middleware sits **outside** the error handler. That is the whole point of
 * its position: an error response is the one a reader is most likely to see in a
 * browser, so it is the last response that may go out without a
 * Content-Security-Policy. Were this middleware inside the error handler, the
 * handler's own response would never pass through it.
 *
 * Existing headers are overwritten — a handler does not get to weaken the
 * policy. The single exception is `Cache-Control`: it is only set when absent,
 * so a response that may legitimately be cached can say so.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', SecurityHeaders::CACHE_CONTROL);
        }

        return $response;
    }
}

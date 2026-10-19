<?php

declare(strict_types=1);

/**
 * Container definitions for F1 — the framework layer.
 *
 * One file per package (U-5). The container globs `config/di/*.php`, merges the
 * arrays in alphabetical order and **refuses to start on a duplicate key**, so
 * adding a package means adding a file, never editing this one — with the single
 * exception marked below.
 *
 * Only what autowiring cannot resolve on its own is declared here: interface to
 * implementation, and values that come from configuration. Everything else PHP-DI
 * builds from the constructor signature.
 *
 * `Paths`, `Config`, `CacheDirectory` and `RuntimeContext` are not listed: they
 * exist before the container does and are registered by
 * {@see \Mailadmin\Kernel\Bootstrap::container()}.
 */

use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Middleware\ErrorHandlerMiddleware;
use Mailadmin\Http\Middleware\JsonBodyMiddleware;
use Mailadmin\Http\Middleware\OpenApiValidationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Middleware\SecurityHeadersMiddleware;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Log\LoggerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;

return [
    // ------------------------------------------------------------------
    // PSR-17: one Psr17Factory serves every factory interface.
    // ------------------------------------------------------------------
    ResponseFactoryInterface::class => static fn (Psr17Factory $factory): Psr17Factory => $factory,
    StreamFactoryInterface::class => static fn (Psr17Factory $factory): Psr17Factory => $factory,
    RequestFactoryInterface::class => static fn (Psr17Factory $factory): Psr17Factory => $factory,
    ServerRequestFactoryInterface::class => static fn (Psr17Factory $factory): Psr17Factory => $factory,
    UriFactoryInterface::class => static fn (Psr17Factory $factory): Psr17Factory => $factory,

    // ------------------------------------------------------------------
    // Logging (U-12). The factory decides between stderr and a rotating file.
    // ------------------------------------------------------------------
    LoggerInterface::class => static fn (LoggerFactory $factory): LoggerInterface => $factory->create(),

    // ------------------------------------------------------------------
    // The middleware chain.
    //
    // Order, outermost first, and why:
    //
    //   1. RequestId          — first, so that every log line below it and every
    //                           response above it carries the same id, including
    //                           the responses the error handler produces.
    //   2. SecurityHeaders    — outside the error handler on purpose. A response
    //                           the error handler creates never passes back
    //                           through anything nested inside it, so putting
    //                           these headers further in would ship every 500
    //                           without a Content-Security-Policy. See
    //                           SecurityHeadersMiddleware.
    //   3. ErrorHandler       — wraps everything that can plausibly throw:
    //                           JSON decoding, routing (F2), handlers, use cases,
    //                           persistence, the mailstore. The two middlewares
    //                           above it are a handful of statements each and
    //                           have no failure mode of their own; the front
    //                           controller catches anything they might still
    //                           raise, along with startup failures.
    //   4. JsonBody           — because the body only needs decoding once
    //                           something is going to read it, and because a
    //                           malformed body should be reported with a request
    //                           id and behind the security headers.
    //   5. OpenApiValidation  — innermost, and immediately in front of the
    //                           dispatcher (F2). It needs the decoded body, and
    //                           everything below it is a handler that may assume
    //                           the request matches the contract. It passes an
    //                           unknown path through, so that "no such route"
    //                           stays the dispatcher's single decision.
    //
    // The innermost handler is the dispatcher compiled from the OpenAPI contract.
    // NotFoundHandler is still built — the dispatcher delegates to it when no
    // route matches, so the `404` document has one shape and one owner.
    // ------------------------------------------------------------------
    MiddlewareDispatcher::class => static fn (
        Dispatcher $innermost,
        RequestIdMiddleware $requestId,
        SecurityHeadersMiddleware $securityHeaders,
        ErrorHandlerMiddleware $errorHandler,
        JsonBodyMiddleware $jsonBody,
        OpenApiValidationMiddleware $openApiValidation,
    ): MiddlewareDispatcher => new MiddlewareDispatcher(
        $innermost,
        $requestId,
        $securityHeaders,
        $errorHandler,
        $jsonBody,
        $openApiValidation,
    ),
];

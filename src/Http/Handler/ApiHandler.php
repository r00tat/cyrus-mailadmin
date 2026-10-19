<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * What every handler in the application is.
 *
 * A plain PSR-15 request handler, and deliberately nothing more. The marker is
 * still worth having for two reasons:
 *
 *  - The route compiler refuses a class that does not implement it, so
 *    "class exists with the right name" cannot be mistaken for "class is a
 *    handler". A typo produces a build error, not a `500` at runtime.
 *  - It documents the contract of the directory: one class per `operationId`,
 *    a request in, a response out, no framework base class to inherit from.
 *
 * By the time a handler runs, three things have already happened: the body was
 * decoded (JsonBodyMiddleware), the request was validated against the contract
 * (OpenApiValidationMiddleware), and the dispatcher put the operation id, the
 * declared permission and the path parameters into request attributes — see
 * {@see \Mailadmin\Http\Dispatcher}. A handler therefore never re-validates
 * input shape; it turns a validated request into a use-case call.
 *
 * Handlers live in `src/Http/Handler/{UpperCamel(first tag)}/` and are named
 * `{UpperCamel(operationId)}Handler` (U-4). Only that first level is scanned,
 * which is why this interface may sit here at the top without being mistaken
 * for a handler that answers nothing.
 */
interface ApiHandler extends RequestHandlerInterface
{
}

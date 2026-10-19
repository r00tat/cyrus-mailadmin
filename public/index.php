<?php

declare(strict_types=1);

/**
 * Front controller — the only PHP entry point.
 *
 * Two deployment models are supported (U-25). Either `public/` is the vHost's
 * DocumentRoot, and everything else is unreachable by construction; or the whole
 * project directory sits inside the web root, and the shipped .htaccess files
 * keep every other directory closed. In both cases no configuration file and no
 * class can be requested directly (architecture, section 3; finding S-4).
 *
 * The second model rests on `AllowOverride All`. `bin/check-deployment.php`
 * verifies that from outside; see docs/deployment/.
 *
 * The whole run sits in a try/catch. That is not a duplicate of
 * ErrorHandlerMiddleware: it covers what happens *before* the chain exists —
 * a missing mandatory environment variable, an unwritable cache directory, a
 * duplicate container definition. Those must produce a clean `problem+json` with
 * the security headers in place and a line in the process log, never a PHP
 * stack trace in the browser.
 */

use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Http\ResponseEmitter;
use Mailadmin\Http\ServerRequestFactory;
use Mailadmin\Http\StartupFailureResponse;
use Psr\Container\ContainerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// U-14: UTC everywhere. Repeated here as well as in Bootstrap::prepare() so that
// it holds even if startup fails before the container is built.
date_default_timezone_set('UTC');

try {
    /** @var mixed $container */
    $container = require dirname(__DIR__) . '/config/bootstrap.php';

    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('config/bootstrap.php must return a PSR-11 container.');
    }

    /** @var mixed $application */
    $application = $container->get(MiddlewareDispatcher::class);

    if (!$application instanceof MiddlewareDispatcher) {
        throw new RuntimeException('The container did not produce the middleware chain.');
    }

    ResponseEmitter::emit($application->handle(ServerRequestFactory::fromGlobals()));
} catch (\Throwable $exception) {
    $requestId = RequestIdMiddleware::generate();

    // No logger available at this point — the container may be the thing that
    // failed. error_log() goes to the SAPI error log, which is where a PHP-FPM
    // startup problem is looked for anyway. Logged before the response is written,
    // so the entry exists even if writing fails.
    error_log(sprintf(
        'mailadmin startup failure [%s]: %s: %s in %s:%d',
        $requestId,
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
    ));

    StartupFailureResponse::emit($requestId);
}

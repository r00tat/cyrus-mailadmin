<?php

declare(strict_types=1);

/**
 * Build step: compile the route table from the bundled contract (U-4, U-8).
 *
 * Reads `var/cache/openapi.bundled.yaml`, maps every operation onto its handler
 * class by the naming convention, and writes `var/cache/routes.php` together
 * with the SHA-256 of the bundle it was made from.
 *
 * It aborts — with the operation named — when
 *
 *   - an operation has no handler class,
 *   - a handler class answers no operation,
 *   - an operation has no `x-permission`,
 *   - an `operationId` is used twice.
 *
 * Run through `composer build`, which bundles first. This command re-bundles as
 * well, so that it cannot compile against a bundle that is older than the
 * sources.
 */

use Mailadmin\Http\Routing\ContractBuild;
use Mailadmin\Http\Routing\RouteCompiler;
use Mailadmin\Http\Routing\SpecificationBundler;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$paths = Paths::detect();

try {
    $context = Bootstrap::readEnvironment($paths);
    $context->cache->ensureUsable(mayCreate: true);

    $build = new ContractBuild(
        bundler: new SpecificationBundler($paths->join('openapi')),
        compiler: new RouteCompiler($paths->join('src', 'Http', 'Handler')),
        cache: $context->cache,
        mayRebuild: true,
    );

    $table = $build->build();

    fwrite(STDOUT, sprintf(
        'Compiled %d routes into %s (base path %s, contract sha256 %s).%s',
        count($table->routes),
        $context->cache->file(ContractBuild::ROUTE_TABLE),
        $table->basePath,
        substr($table->specChecksum, 0, 12),
        PHP_EOL,
    ));

    foreach ($table->routes as $route) {
        fwrite(STDOUT, sprintf(
            '  %-6s %-40s %-24s %s%s',
            $route->method,
            $route->path,
            $route->operationId,
            $route->permission,
            PHP_EOL,
        ));
    }
} catch (\Throwable $error) {
    fwrite(STDERR, 'Route compilation failed: ' . $error->getMessage() . PHP_EOL);

    exit(1);
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

/**
 * Removes the mount prefix from a request path.
 *
 * The contract knows paths as `/api/v1/health` (U-10). An installation that is
 * not mounted at the web root prepends `MAILADMIN_BASE_PATH`, and that prefix is
 * deployment detail the contract must not learn about.
 *
 * This lives in one place on purpose. Both the schema validation middleware and
 * the dispatcher have to strip the same prefix; if they disagreed by one
 * character, the validator would find no operation, wave the request through,
 * and the dispatcher would still route it — an unvalidated request reaching a
 * handler. {@see \Mailadmin\Http\Dispatcher} additionally refuses to run a
 * handler whose request carries no validation mark, so that such a disagreement
 * fails closed rather than silently.
 */
final class BasePath
{
    private function __construct()
    {
    }

    /**
     * @param string $basePath normalised by {@see \Mailadmin\Config\Config::basePath()}:
     *                         either empty or a leading slash without a trailing one
     */
    public static function strip(string $path, string $basePath): string
    {
        if ($basePath === '') {
            return $path;
        }

        if ($path === $basePath) {
            return '/';
        }

        if (str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath));
        }

        return $path;
    }
}

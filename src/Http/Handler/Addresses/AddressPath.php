<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The path and query parameters of the A3 handlers, read the same way by all
 * of them.
 *
 * `{alias}` and `{account}` arrive URL-encoded (`info%40example.test`, U-18)
 * and are decoded here; `{domain}` and `{entry}` cannot carry anything that
 * needs it.
 */
final class AddressPath
{
    private function __construct()
    {
    }

    public static function domain(ServerRequestInterface $request): string
    {
        return self::attribute($request, 'domain');
    }

    public static function entry(ServerRequestInterface $request): string
    {
        return self::attribute($request, 'entry');
    }

    public static function alias(ServerRequestInterface $request): string
    {
        return rawurldecode(self::attribute($request, 'alias'));
    }

    public static function account(ServerRequestInterface $request): string
    {
        return rawurldecode(self::attribute($request, 'account'));
    }

    /**
     * A boolean query parameter. The contract has validated it already; what
     * arrives is the string a URL carries.
     */
    public static function flag(ServerRequestInterface $request, string $name): bool
    {
        /** @var mixed $value */
        $value = $request->getQueryParams()[$name] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        return is_string($value) && in_array(strtolower($value), ['true', '1'], true);
    }

    private static function attribute(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : '';
    }
}

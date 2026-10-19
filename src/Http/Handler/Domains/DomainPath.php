<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Domains;

use Mailadmin\Http\Dispatcher;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The `{domain}` path parameter, read the same way by three handlers.
 *
 * The dispatcher sets each path parameter as a request attribute under its own
 * name (see {@see Dispatcher}). Reading it through one function rather than
 * three `getAttribute('domain')` calls means the narrowing PHPStan wants is
 * written once, and that a change to how path parameters arrive is a change to
 * one file.
 *
 * It returns the raw string. Turning it into a
 * {@see \Mailadmin\Domain\Model\DomainName} is the handler's business, because
 * only the handler knows whether an unusable value should be a `404` (it looks
 * up an existing domain) or a `422` (it is creating one).
 */
final class DomainPath
{
    public const string PARAMETER = 'domain';

    private function __construct()
    {
    }

    public static function of(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(self::PARAMETER);

        return is_string($value) ? $value : '';
    }
}

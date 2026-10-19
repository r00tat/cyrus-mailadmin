<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The `{account}` and `{domain}` path parameters, read the same way by every
 * account handler.
 *
 * `{account}` is the stored Cyrus user name, URL-encoded (U-18). The router
 * hands the raw segment over, so it is decoded here — `carl%40example.test` is
 * `carl@example.test` — and then passed on verbatim, never taken apart
 * (architecture 3.3).
 */
final class AccountPath
{
    public const string ACCOUNT = 'account';

    public const string DOMAIN = 'domain';

    private function __construct()
    {
    }

    public static function account(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(self::ACCOUNT);

        return is_string($value) ? rawurldecode($value) : '';
    }

    public static function domain(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(self::DOMAIN);

        return is_string($value) ? $value : '';
    }
}

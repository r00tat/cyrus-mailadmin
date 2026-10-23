<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading the pieces of a request that four handlers all need.
 *
 * ## The path parameter is a name, not an id
 *
 * `lists` has no surrogate key — `name` is the primary key (analysis 4.1) — so
 * `{listName}` carries the name, URL-encoded (U-60). Same reasoning as U-18 for
 * accounts: a numeric id would mean a second notion of identity for a row that
 * an external tool also writes, and keeping two of those in step is work that
 * buys nothing.
 *
 * The router does not URL-decode it, so this does. The value is passed on **whole** and is
 * never taken apart.
 *
 * ## `If-Match`
 *
 * Optional (`components/common.yaml`), so an absent header is `null` and not an
 * error. It is the use case that decides what follows, because "accepted but
 * ignored" and "accepted and compared" look the same from here.
 */
final class MailingListPath
{
    public const string PARAMETER = 'listName';

    private function __construct()
    {
    }

    public static function name(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(self::PARAMETER);

        return is_string($value) ? rawurldecode($value) : '';
    }

    public static function ifMatch(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('If-Match');

        return trim($header) === '' ? null : $header;
    }
}

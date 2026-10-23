<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading the pieces of a request that four handlers all need.
 *
 * ## The path parameter is the list address
 *
 * `lists.name` is display text and may repeat; the address is what identifies
 * a list (spec 4.3, "Identität"), so `{address}` carries it, URL-encoded
 * (`team%40example.test`). A numeric id would mean a second notion of identity
 * for a row that an external tool also writes.
 *
 * The router does not URL-decode it, so this does. The value is passed on
 * **whole** and is never taken apart; the use case parses it.
 *
 * ## `If-Match`
 *
 * Optional (`components/common.yaml`), so an absent header is `null` and not an
 * error. It is the use case that decides what follows, because "accepted but
 * ignored" and "accepted and compared" look the same from here.
 */
final class MailingListPath
{
    public const string PARAMETER = 'address';

    private function __construct()
    {
    }

    public static function address(ServerRequestInterface $request): string
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

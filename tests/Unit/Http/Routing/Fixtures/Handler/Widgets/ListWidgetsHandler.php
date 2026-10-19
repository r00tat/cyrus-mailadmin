<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Routing\Fixtures\Handler\Widgets;

use Mailadmin\Http\Handler\ApiHandler;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A handler for a contract that exists only in the tests.
 *
 * The fixtures use an invented resource ("widgets") on purpose: the packages
 * that come after F2 own the real ones, and a test that referenced `domains`
 * would start failing the day A1 lands.
 */
final class ListWidgetsHandler implements ApiHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{"items":[],"meta":{"page":1,"per_page":50,"total":0}}'));
    }
}

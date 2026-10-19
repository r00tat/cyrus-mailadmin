<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Routing\Fixtures\Handler\Widgets;

use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Handler\ApiHandler;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Echoes back what the dispatcher put into the request, so that the tests can
 * assert on the attributes a real handler is entitled to rely on.
 */
final class CreateWidgetHandler implements ApiHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_encode([
            'operation_id' => $request->getAttribute(Dispatcher::ATTRIBUTE_OPERATION_ID),
            'permission' => $request->getAttribute(Dispatcher::ATTRIBUTE_PERMISSION),
            'path_parameters' => $request->getAttribute(Dispatcher::ATTRIBUTE_PATH_PARAMETERS),
            // Every path parameter is also an attribute under its own name,
            // which is how a handler normally reads one.
            'widget_id' => $request->getAttribute('widgetId'),
        ], JSON_THROW_ON_ERROR);

        return (new Response(201))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Location', '/api/v1/widgets/1')
            ->withBody(Stream::create($body));
    }
}

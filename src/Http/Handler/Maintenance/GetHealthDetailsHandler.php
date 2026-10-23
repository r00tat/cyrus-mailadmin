<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Maintenance;

use Mailadmin\Application\Maintenance\GetHealthDetails;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /maintenance/health`. Always `200`: the request worked, whatever the
 * dependencies are doing — `status` in the body carries the verdict.
 */
final readonly class GetHealthDetailsHandler implements ApiHandler
{
    public function __construct(
        private GetHealthDetails $details,
        private MaintenanceResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        return $this->responder->json(200, $this->details->handle($actor)->toArray());
    }
}

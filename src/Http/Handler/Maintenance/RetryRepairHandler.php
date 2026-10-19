<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Maintenance;

use Mailadmin\Application\Maintenance\RepairOperationFailed;
use Mailadmin\Application\Maintenance\RetryRepair;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /maintenance/repairs/{repair}/retry`.
 */
final readonly class RetryRepairHandler implements ApiHandler
{
    public function __construct(
        private RetryRepair $retry,
        private MaintenanceResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);
        $id = MaintenanceResponder::repairId($request);

        if ($id === null) {
            return $this->responder->notFound($request);
        }

        try {
            return $this->responder->entry($this->retry->handle($actor, $id));
        } catch (RepairOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Maintenance;

use Mailadmin\Application\Maintenance\RepairOperationFailed;
use Mailadmin\Application\Maintenance\ResolveRepair;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /maintenance/repairs/{repair}/resolve`. The body is optional; without
 * one the entry is dismissed without a note.
 */
final readonly class ResolveRepairHandler implements ApiHandler
{
    public function __construct(
        private ResolveRepair $resolve,
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

        $note = ParsedBody::text(ParsedBody::of($request), 'note');

        try {
            return $this->responder->entry($this->resolve->handle($actor, $id, $note));
        } catch (RepairOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }
    }
}

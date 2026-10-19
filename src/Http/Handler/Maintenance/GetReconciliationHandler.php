<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Maintenance;

use Mailadmin\Application\Maintenance\Reconcile;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /maintenance/reconciliation`.
 *
 * A mail store that cannot be listed is `503` and not a report: comparing the
 * database against nothing would list every account as missing its mailbox.
 */
final readonly class GetReconciliationHandler implements ApiHandler
{
    public function __construct(
        private Reconcile $reconcile,
        private MaintenanceResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $report = $this->reconcile->handle($actor);
        } catch (MailstoreFailure $failure) {
            return $this->responder->unavailable($request, $failure->errorCode());
        }

        return $this->responder->json(200, $report->toRepresentation());
    }
}

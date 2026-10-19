<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\GetAdmin;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admins/{username}`.
 *
 * The response carries the `ETag` a later `PATCH` or `DELETE` can send back as
 * `If-Match` (U-19).
 */
final readonly class GetAdminHandler implements ApiHandler
{
    public function __construct(
        private GetAdmin $getAdmin,
        private AdminResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $admin = $this->getAdmin->handle(
                AdminResponder::actorOf($request),
                AdminResponder::pathParameter($request, 'username'),
            );
        } catch (AdminOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->admin($admin);
    }
}

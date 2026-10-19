<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\DeleteAdmin;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /admins/{username}`.
 *
 * `204` and no body. The refusals — the last superuser, oneself — are the use
 * case's and arrive here as {@see AdminOperationFailed} with the status already
 * decided.
 */
final readonly class DeleteAdminHandler implements ApiHandler
{
    public function __construct(
        private DeleteAdmin $deleteAdmin,
        private AdminResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->deleteAdmin->handle(
                AdminResponder::actorOf($request),
                AdminResponder::pathParameter($request, 'username'),
                $request->hasHeader('If-Match') ? $request->getHeaderLine('If-Match') : null,
            );
        } catch (AdminOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->noContent();
    }
}

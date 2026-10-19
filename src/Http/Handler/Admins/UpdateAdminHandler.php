<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\UpdateAdmin;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /admins/{username}`.
 *
 * **Absent and empty are different here**, and this handler is where the
 * difference is read off the wire: a body without `domains` passes `null` and
 * leaves the assignment alone, a body with `"domains": []` passes an empty list
 * and is an attempt to clear it. `array_key_exists()` rather than `??`, because
 * `??` cannot tell the two apart.
 *
 * The `If-Match` header is handed on rather than evaluated: the precondition is
 * the use case's, so that a second caller cannot skip it (see
 * {@see \Mailadmin\Application\Admin\AdminEtag}).
 */
final readonly class UpdateAdminHandler implements ApiHandler
{
    public function __construct(
        private UpdateAdmin $updateAdmin,
        private AdminResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = ParsedBody::of($request);

        $role = null;
        if (array_key_exists('role', $body)) {
            $role = AdminRole::tryFrom(ParsedBody::text($body, 'role'));

            if ($role === null) {
                return $this->responder->unprocessable($request, 'The role must be superuser or domainmaster.');
            }
        }

        $ifMatch = $request->hasHeader('If-Match') ? $request->getHeaderLine('If-Match') : null;

        try {
            $admin = $this->updateAdmin->handle(
                actor: AdminResponder::actorOf($request),
                username: AdminResponder::pathParameter($request, 'username'),
                role: $role,
                domains: array_key_exists('domains', $body) ? CreateAdminHandler::domains($body) : null,
                password: array_key_exists('password', $body) ? ParsedBody::text($body, 'password') : null,
                ifMatch: $ifMatch,
            );
        } catch (AdminOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (InvalidPassword $refused) {
            return $this->responder->invalidPassword($request, $refused);
        }

        return $this->responder->admin($admin);
    }
}

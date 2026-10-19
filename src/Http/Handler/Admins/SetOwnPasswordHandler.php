<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\ChangeOwnPassword;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /admins/me/password`.
 *
 * **There is no user name in this handler.** The account is the one the verified
 * token names, and there is no parameter through which the request could point
 * the operation somewhere else — which is what makes it safe to grant
 * `profile.password.write` to every role. Setting another administrator's
 * password is `PATCH /admins/{username}` and needs `admin.write`.
 *
 * `204` and no body: nothing about the new password is worth echoing, and the
 * client already knows what it sent.
 */
final readonly class SetOwnPasswordHandler implements ApiHandler
{
    public function __construct(
        private ChangeOwnPassword $changeOwnPassword,
        private AdminResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = ParsedBody::of($request);

        try {
            $this->changeOwnPassword->handle(
                AdminResponder::actorOf($request),
                ParsedBody::text($body, 'current_password'),
                ParsedBody::text($body, 'new_password'),
            );
        } catch (AdminOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (InvalidPassword $refused) {
            return $this->responder->invalidPassword($request, $refused);
        }

        return $this->responder->noContent();
    }
}

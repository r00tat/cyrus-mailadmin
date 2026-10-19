<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\SetAccountPassword;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /accounts/{account}/password`.
 *
 * The new hash is bcrypt, the one format both this application and
 * `pam_mysql`'s `crypt(3)` read. Nothing about the password is echoed.
 */
final readonly class SetAccountPasswordHandler implements ApiHandler
{
    public function __construct(
        private SetAccountPassword $setPassword,
        private AccountResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        /** @var mixed $password */
        $password = ParsedBody::of($request)['password'] ?? '';

        try {
            $this->setPassword->handle($actor, AccountPath::account($request), is_string($password) ? $password : '');
        } catch (AccountOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (InvalidPassword $error) {
            return $this->responder->invalidPassword($request, $error);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, 'body.password');
        }

        return $this->responder->noContent();
    }
}

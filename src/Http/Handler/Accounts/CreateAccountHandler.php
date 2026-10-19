<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountDraft;
use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\CreateAccount;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /domains/{domain}/accounts`.
 *
 * A refused password is `422` with the rule's code and nothing of the input. A
 * refused local part is `422` naming `body.local_part`. The order of the steps
 * and what happens when one fails is {@see CreateAccount}'s.
 */
final readonly class CreateAccountHandler implements ApiHandler
{
    public function __construct(
        private CreateAccount $createAccount,
        private AccountResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $domain = DomainName::fromStoredValue(AccountPath::domain($request));
        } catch (DomainError) {
            return $this->responder->notFound($request);
        }

        try {
            $draft = AccountDraft::fromRequest(ParsedBody::of($request));
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, 'body.local_part');
        }

        try {
            $created = $this->createAccount->handle($actor, $domain, $draft);
        } catch (AccountOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (InvalidPassword $error) {
            return $this->responder->invalidPassword($request, $error);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, 'body');
        }

        return $this->responder->created($created);
    }
}

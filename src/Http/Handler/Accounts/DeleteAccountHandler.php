<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\DeleteAccount;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /accounts/{account}` — requires `If-Match` (U-83).
 *
 * The database rows go first, then the mailbox. A mailbox that cannot be removed
 * afterwards is a repair-queue entry, not a failed request.
 */
final readonly class DeleteAccountHandler implements ApiHandler
{
    public function __construct(
        private DeleteAccount $deleteAccount,
        private AccountResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $this->deleteAccount->handle($actor, AccountPath::account($request), AccountResponder::ifMatch($request));
        } catch (AccountOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->noContent();
    }
}

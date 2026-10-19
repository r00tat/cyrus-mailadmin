<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\GetAccount;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /accounts/{account}`.
 *
 * An account whose stored name does not fit its domain is still shown, marked
 * `resolvable: false` — it is displayed, not skipped (CLAUDE.md).
 */
final readonly class GetAccountHandler implements ApiHandler
{
    public function __construct(
        private GetAccount $getAccount,
        private AccountResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $record = $this->getAccount->handle($actor, AccountPath::account($request));
        } catch (AccountOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->account($record);
    }
}

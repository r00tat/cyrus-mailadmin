<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\GetAccountQuota;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /accounts/{account}/quota` — limit and usage, read from Cyrus.
 *
 * A resource of its own because it costs a round trip to the mail store that
 * the account itself does not (U-83).
 */
final readonly class GetAccountQuotaHandler implements ApiHandler
{
    public function __construct(
        private GetAccountQuota $getQuota,
        private AccountResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $quota = $this->getQuota->handle($actor, AccountPath::account($request));
        } catch (AccountOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->quota($quota);
    }
}

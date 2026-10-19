<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\UpdateAccount;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /accounts/{account}` — the service flags and `enabled`.
 *
 * The flags are stored, not enforced: the authentication chain does not read
 * them (U-78). `enabled` is enforced through the view `allusers` (U-127).
 */
final readonly class UpdateAccountHandler implements ApiHandler
{
    public function __construct(
        private UpdateAccount $updateAccount,
        private AccountResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        $body = ParsedBody::of($request);

        /** @var mixed $services */
        $services = $body['services'] ?? [];

        /** @var mixed $enabled */
        $enabled = $body['enabled'] ?? null;

        try {
            $record = $this->updateAccount->handle(
                $actor,
                AccountPath::account($request),
                is_array($services) ? $services : [],
                AccountResponder::ifMatch($request),
                is_bool($enabled) ? $enabled : null,
            );
        } catch (AccountOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->account($record);
    }
}

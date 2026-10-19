<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\SetAccountQuota;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /accounts/{account}/quota`.
 *
 * `limit_kib: null` removes the limit; `0` is refused by the contract (U-79).
 */
final readonly class SetAccountQuotaHandler implements ApiHandler
{
    public function __construct(
        private SetAccountQuota $setQuota,
        private AccountResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        /** @var mixed $limit */
        $limit = ParsedBody::of($request)['limit_kib'] ?? null;

        try {
            $requested = is_int($limit) ? Quota::ofKibibytes($limit) : Quota::unlimited();
            $quota = $this->setQuota->handle($actor, AccountPath::account($request), $requested);
        } catch (AccountOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, 'body.limit_kib');
        }

        return $this->responder->quota($quota);
    }
}

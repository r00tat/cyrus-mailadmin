<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Accounts;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Application\Account\ListAccounts;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /domains/{domain}/accounts`.
 *
 * The scoping is the policy's: a domain the actor cannot see is the same `404`
 * as one that does not exist (U-44).
 */
final readonly class ListAccountsHandler implements ApiHandler
{
    public function __construct(
        private ListAccounts $listAccounts,
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
            $page = $this->listAccounts->handle($actor, $domain, ListCriteria::fromQuery($request->getQueryParams()));
        } catch (CriteriaError $error) {
            return $this->responder->badCriteria($request, $error);
        } catch (AccountOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->collection($page);
    }
}

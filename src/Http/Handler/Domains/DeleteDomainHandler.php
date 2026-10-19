<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Domains;

use Mailadmin\Application\Domain\DeleteDomain;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /domains/{domain}`.
 *
 * Nothing is decided here. Whether the domain may go — it may not while it has
 * accounts (U-49) — and whether the client's `If-Match` still describes it are
 * both {@see DeleteDomain}'s, where they can be tested without a request object
 * and where they apply to a command-line caller as well.
 */
final readonly class DeleteDomainHandler implements ApiHandler
{
    public function __construct(
        private DeleteDomain $deleteDomain,
        private DomainResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $name = DomainName::fromStoredValue(DomainPath::of($request));
        } catch (DomainError) {
            return $this->responder->notFound($request);
        }

        try {
            $this->deleteDomain->handle($actor, $name, DomainResponder::ifMatch($request));
        } catch (DomainOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->noContent($request);
    }
}

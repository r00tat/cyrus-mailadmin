<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\DeleteCatchall;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /domains/{domain}/catchall` — idempotent.
 */
final readonly class DeleteCatchallHandler implements ApiHandler
{
    public function __construct(
        private DeleteCatchall $useCase,
        private AddressResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $domain = DomainName::fromStoredValue(AddressPath::domain($request));
        } catch (DomainError) {
            return $this->responder->notFound($request);
        }

        try {
            $this->useCase->handle($actor, $domain, AddressResponder::ifMatch($request));
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->noContent();
    }
}

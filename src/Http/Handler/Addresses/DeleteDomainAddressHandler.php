<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\DeleteDomainAddress;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /domains/{domain}/addresses/{entry}` — one row, whatever its type (U-103).
 */
final readonly class DeleteDomainAddressHandler implements ApiHandler
{
    public function __construct(
        private DeleteDomainAddress $useCase,
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
            $this->useCase->handle(
                $actor,
                $domain,
                AddressPath::entry($request),
                AddressPath::flag($request, 'confirm_reserved'),
            );
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->noContent();
    }
}

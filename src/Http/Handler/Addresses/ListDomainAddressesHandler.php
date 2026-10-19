<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\ListDomainAddresses;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /domains/{domain}/addresses` — every row, including the unknown ones (U-22, U-103).
 */
final readonly class ListDomainAddressesHandler implements ApiHandler
{
    public function __construct(
        private ListDomainAddresses $useCase,
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
            $listing = $this->useCase->handle($actor, $domain, ListCriteria::fromQuery($request->getQueryParams()));
        } catch (CriteriaError $error) {
            return $this->responder->badCriteria($request, $error);
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->listing($listing);
    }
}

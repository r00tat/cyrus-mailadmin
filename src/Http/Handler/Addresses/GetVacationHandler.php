<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\GetVacation;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /accounts/{account}/vacation` (U-102).
 */
final readonly class GetVacationHandler implements ApiHandler
{
    public function __construct(
        private GetVacation $useCase,
        private AddressResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $state = $this->useCase->handle($actor, AddressPath::account($request));
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->vacation($state);
    }
}

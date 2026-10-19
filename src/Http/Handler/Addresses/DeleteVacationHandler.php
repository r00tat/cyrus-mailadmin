<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\DeleteVacation;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /accounts/{account}/vacation` — a foreign script only with `take_over`.
 */
final readonly class DeleteVacationHandler implements ApiHandler
{
    public function __construct(
        private DeleteVacation $useCase,
        private AddressResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $this->useCase->handle(
                $actor,
                AddressPath::account($request),
                AddressPath::flag($request, 'take_over'),
                AddressResponder::ifMatch($request),
            );
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->noContent();
    }
}

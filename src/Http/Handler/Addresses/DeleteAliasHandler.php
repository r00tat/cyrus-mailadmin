<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\DeleteAlias;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /aliases/{alias}`.
 */
final readonly class DeleteAliasHandler implements ApiHandler
{
    public function __construct(
        private DeleteAlias $useCase,
        private AddressResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $this->useCase->handle(
                $actor,
                AddressPath::alias($request),
                AddressPath::flag($request, 'confirm_reserved'),
                AddressResponder::ifMatch($request),
            );
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->noContent();
    }
}

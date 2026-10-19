<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\GetForwards;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /accounts/{account}/forwards`.
 */
final readonly class GetForwardsHandler implements ApiHandler
{
    public function __construct(
        private GetForwards $useCase,
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

        return $this->responder->forwards($state);
    }
}

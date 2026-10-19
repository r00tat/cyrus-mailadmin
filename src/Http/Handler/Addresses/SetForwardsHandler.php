<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\ForwardChange;
use Mailadmin\Application\Address\SetForwards;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /accounts/{account}/forwards` — a row in `virtual`, never a Sieve redirect (U-107).
 */
final readonly class SetForwardsHandler implements ApiHandler
{
    public function __construct(
        private SetForwards $useCase,
        private AddressResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        $body = ParsedBody::of($request);
        /** @var mixed $destinations */
        $destinations = $body['destinations'] ?? [];

        try {
            $state = $this->useCase->handle(
                $actor,
                AddressPath::account($request),
                new ForwardChange(
                    is_array($destinations) ? $destinations : [],
                    ($body['keep_copy'] ?? true) !== false,
                    ($body['enabled'] ?? true) !== false,
                ),
                AddressResponder::ifMatch($request),
            );
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, AddressResponder::fieldOf($error));
        }

        return $this->responder->forwards($state);
    }
}

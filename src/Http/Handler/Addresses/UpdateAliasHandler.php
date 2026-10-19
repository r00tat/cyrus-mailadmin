<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\AliasChange;
use Mailadmin\Application\Address\UpdateAlias;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /aliases/{alias}` — rewritten as one row (U-105).
 */
final readonly class UpdateAliasHandler implements ApiHandler
{
    public function __construct(
        private UpdateAlias $useCase,
        private AddressResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        $body = ParsedBody::of($request);
        /** @var mixed $destinations */
        $destinations = $body['destinations'] ?? null;
        /** @var mixed $sender */
        $sender = $body['sender_account'] ?? null;
        /** @var mixed $enabled */
        $enabled = $body['enabled'] ?? null;

        try {
            $state = $this->useCase->handle(
                $actor,
                AddressPath::alias($request),
                new AliasChange(
                    is_array($destinations) ? $destinations : null,
                    array_key_exists('sender_account', $body),
                    is_string($sender) ? $sender : null,
                    is_bool($enabled) ? $enabled : null,
                    ($body['confirm_reserved'] ?? false) === true,
                ),
                AddressResponder::ifMatch($request),
            );
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, AddressResponder::fieldOf($error));
        }

        return $this->responder->alias($state);
    }
}

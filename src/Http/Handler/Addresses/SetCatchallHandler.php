<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\CatchallChange;
use Mailadmin\Application\Address\SetCatchall;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /domains/{domain}/catchall` (U-106, U-108).
 */
final readonly class SetCatchallHandler implements ApiHandler
{
    public function __construct(
        private SetCatchall $useCase,
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

        $body = ParsedBody::of($request);
        /** @var mixed $destinations */
        $destinations = $body['destinations'] ?? [];
        /** @var mixed $sender */
        $sender = $body['sender_account'] ?? null;

        try {
            $state = $this->useCase->handle(
                $actor,
                $domain,
                new CatchallChange(
                    is_array($destinations) ? $destinations : [],
                    ($body['enabled'] ?? true) !== false,
                    array_key_exists('sender_account', $body),
                    is_string($sender) ? $sender : null,
                ),
                AddressResponder::ifMatch($request),
            );
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, AddressResponder::fieldOf($error));
        }

        return $this->responder->catchall($state);
    }
}

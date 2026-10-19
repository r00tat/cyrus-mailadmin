<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\AliasDraft;
use Mailadmin\Application\Address\CreateAlias;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /domains/{domain}/aliases`.
 */
final readonly class CreateAliasHandler implements ApiHandler
{
    public function __construct(
        private CreateAlias $useCase,
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
            $state = $this->useCase->handle($actor, $domain, new AliasDraft(
                is_string($body['local_part'] ?? null) ? $body['local_part'] : '',
                is_array($destinations) ? $destinations : [],
                is_string($sender) ? $sender : null,
                ($body['enabled'] ?? true) !== false,
            ));
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, AddressResponder::fieldOf($error));
        }

        return $this->responder->aliasCreated($state);
    }
}

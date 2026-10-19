<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\GetCatchall;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /domains/{domain}/catchall`.
 */
final readonly class GetCatchallHandler implements ApiHandler
{
    public function __construct(
        private GetCatchall $useCase,
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
            $state = $this->useCase->handle($actor, $domain);
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->catchall($state);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Addresses;

use Mailadmin\Application\Address\AddressOperationFailed;
use Mailadmin\Application\Address\SetVacation;
use Mailadmin\Application\Address\VacationDraft;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /accounts/{account}/vacation` — rendered and installed by M3 (U-102).
 */
final readonly class SetVacationHandler implements ApiHandler
{
    public function __construct(
        private SetVacation $useCase,
        private AddressResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        $body = ParsedBody::of($request);
        /** @var mixed $subject */
        $subject = $body['subject'] ?? null;
        /** @var mixed $interval */
        $interval = $body['interval_days'] ?? null;
        /** @var mixed $addresses */
        $addresses = $body['addresses'] ?? null;

        try {
            $state = $this->useCase->handle(
                $actor,
                AddressPath::account($request),
                new VacationDraft(
                    is_string($body['message'] ?? null) ? $body['message'] : '',
                    is_string($subject) ? $subject : null,
                    is_int($interval) ? $interval : null,
                    is_array($addresses) ? array_values(array_filter($addresses, 'is_string')) : null,
                    ($body['take_over'] ?? false) === true,
                ),
                AddressResponder::ifMatch($request),
            );
        } catch (AddressOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, AddressResponder::fieldOf($error, 'body.addresses'));
        }

        return $this->responder->vacation($state);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Mailadmin\Application\MailingList\DeleteMailingList;
use Mailadmin\Application\MailingList\MailingListFailure;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /lists/{address}`.
 *
 * `204` and no body — and the `X-Mailadmin-Effect` header, which is the reason
 * the notice about the configuration run is a header at all: this is the
 * response with no body to put it in, and it is the response whose delay is
 * least expected. The address keeps working until the next configuration run.
 */
final readonly class DeleteMailingListHandler implements ApiHandler
{
    public function __construct(
        private DeleteMailingList $lists,
        private MailingListResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = MailingListResponder::actor($request);

        try {
            $this->lists->handle(
                actor: $actor,
                address: MailingListPath::address($request),
                ifMatch: MailingListPath::ifMatch($request),
            );
        } catch (MailingListFailure $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->deleted();
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Mailadmin\Application\MailingList\GetMailingList;
use Mailadmin\Application\MailingList\MailingListFailure;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /lists/{listName}`.
 *
 * Answers with an `ETag` so that the next `PATCH` or `DELETE` can carry
 * `If-Match` (U-19). That is the only reason a single-list read exists beside
 * the collection: without a validator, two administrators editing the same list
 * overwrite each other and neither is told.
 */
final readonly class GetMailingListHandler implements ApiHandler
{
    public function __construct(
        private GetMailingList $lists,
        private MailingListResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = MailingListResponder::actor($request);

        try {
            return $this->responder->single(
                $this->lists->handle($actor, MailingListPath::name($request)),
            );
        } catch (MailingListFailure $failure) {
            return $this->responder->failure($request, $failure);
        }
    }
}

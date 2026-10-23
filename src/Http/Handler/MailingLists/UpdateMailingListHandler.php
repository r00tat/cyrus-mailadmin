<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Mailadmin\Application\MailingList\MailingListFailure;
use Mailadmin\Application\MailingList\UpdateMailingList;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /lists/{listName}`.
 *
 * A `PATCH` and not a `PUT`, and that is not a style preference: `members` is a
 * column an external tool writes and the configuration management reads (E-4).
 * With `PUT`, a client that omitted the field would be asking for it to be
 * cleared, and the first administrator who corrected a tag with a hand-written
 * request would empty a list. With `PATCH`, an omitted field is left alone —
 * see {@see MailingListBody}.
 */
final readonly class UpdateMailingListHandler implements ApiHandler
{
    public function __construct(
        private UpdateMailingList $lists,
        private MailingListResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = MailingListResponder::actor($request);

        try {
            $list = $this->lists->handle(
                actor: $actor,
                address: MailingListPath::name($request),
                changes: MailingListBody::changes(ParsedBody::of($request)),
                ifMatch: MailingListPath::ifMatch($request),
            );
        } catch (MailingListFailure $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->changed($list);
    }
}

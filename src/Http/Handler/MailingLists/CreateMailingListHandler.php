<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Mailadmin\Application\MailingList\CreateMailingList;
use Mailadmin\Application\MailingList\MailingListFailure;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /lists`.
 *
 * `members` is optional and defaults to the empty list: a list with an address
 * and no members is a legitimate state — the test fixtures contain one — and
 * demanding at least one member would mean an administrator cannot create a list
 * before knowing who is in it.
 */
final readonly class CreateMailingListHandler implements ApiHandler
{
    public function __construct(
        private CreateMailingList $lists,
        private MailingListResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = MailingListResponder::actor($request);
        $body = ParsedBody::of($request);

        try {
            $list = $this->lists->handle(
                actor: $actor,
                name: ParsedBody::text($body, 'name'),
                tag: ParsedBody::text($body, 'tag'),
                address: ParsedBody::text($body, 'address'),
                members: MailingListBody::members($body) ?? [],
            );
        } catch (MailingListFailure $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->created($request, $list);
    }
}

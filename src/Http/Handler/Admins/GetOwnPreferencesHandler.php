<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\GetOwnPreferences;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admins/me/preferences` (W2).
 *
 * No user name anywhere: the preferences are those of the verified token.
 */
final readonly class GetOwnPreferencesHandler implements ApiHandler
{
    public function __construct(
        private GetOwnPreferences $getOwnPreferences,
        private AdminResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->preferences(
            $this->getOwnPreferences->handle(AdminResponder::actorOf($request)),
        );
    }
}

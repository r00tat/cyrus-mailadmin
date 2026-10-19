<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Auth;

use Mailadmin\Application\Auth\AuthenticationFailed;
use Mailadmin\Application\Auth\RefreshDelivery;
use Mailadmin\Application\Auth\RefreshSession;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Mailadmin\Http\RefreshCookie;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /auth/refresh`.
 *
 * ## The token comes back the way it went out
 *
 * A token sent in the body is answered in the body; one sent in the cookie is
 * answered in the cookie. No flag decides it — the channel the client used *is*
 * the statement of which client it is, and a browser cannot accidentally ask for
 * the body variant by omitting a field.
 *
 * When both are present the body wins, because sending it took a deliberate act
 * while the cookie is sent automatically. That combination happens to a
 * command-line client testing against a browser session, and picking the
 * deliberate one is the less surprising answer.
 */
final readonly class RefreshSessionHandler implements ApiHandler
{
    public function __construct(
        private RefreshSession $refresh,
        private RefreshCookie $cookie,
        private SessionResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $fromBody = ParsedBody::text(ParsedBody::of($request), 'refresh_token');
        $fromCookie = $this->cookie->readFrom($request);

        $presented = $fromBody !== '' ? $fromBody : $fromCookie;
        $delivery = $fromBody !== '' ? RefreshDelivery::Body : RefreshDelivery::Cookie;

        try {
            $session = $this->refresh->handle($presented);
        } catch (AuthenticationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->session($request, $session, $delivery);
    }
}

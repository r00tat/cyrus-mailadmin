<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Auth;

use Mailadmin\Application\Auth\AuthenticationFailed;
use Mailadmin\Application\Auth\RefreshDelivery;
use Mailadmin\Application\Auth\SignIn;
use Mailadmin\Http\ClientAddress;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /auth/token`.
 *
 * Reads the validated body, calls the use case, renders. Every decision — the
 * rate limit, the dummy verification for an unknown name, the rehash of a legacy
 * hash — lives in {@see SignIn}, where it is testable without a request object.
 *
 * Note what the handler does **not** do: it does not log the attempt, does not
 * decide the status code, and does not know that a wrong password and an unknown
 * user name are answered alike. All three would be easy to get subtly wrong
 * twice if the refresh endpoint had its own copy.
 */
final readonly class IssueTokenHandler implements ApiHandler
{
    public function __construct(
        private SignIn $signIn,
        private SessionResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = ParsedBody::of($request);

        try {
            $session = $this->signIn->handle(
                username: ParsedBody::text($body, 'username'),
                password: ParsedBody::text($body, 'password'),
                clientIp: ClientAddress::of($request),
            );
        } catch (AuthenticationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->session(
            $request,
            $session,
            RefreshDelivery::fromRequestValue(ParsedBody::text($body, 'refresh_delivery', 'cookie')),
        );
    }
}

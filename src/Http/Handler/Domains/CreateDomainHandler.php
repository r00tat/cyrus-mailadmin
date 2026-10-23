<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Domains;

use Mailadmin\Application\Domain\CreateDomain;
use Mailadmin\Application\Domain\DomainDraft;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /domains`.
 *
 * Two kinds of refusal reach a client from here and they are not the same thing:
 *
 *  - a **value** the contract accepted and the model did not — a name with an
 *    underscore, a folder with an `@` — is `422` carrying the model's own stable
 *    code (`domain.invalid_name`, `domain.invalid_folder`), so the frontend can
 *    point at the field;
 *  - a **state** — the name is taken, the legacy prefix column is full — is
 *    `409`, because the request was fine and the world was not.
 *
 * A domain master never gets here: `domain.write` is superuser-only and
 * `AuthorizationMiddleware` refuses ahead of the handler.
 */
final readonly class CreateDomainHandler implements ApiHandler
{
    public function __construct(
        private CreateDomain $createDomain,
        private DomainResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $draft = DomainDraft::fromRequest(ParsedBody::of($request));
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, 'body.name');
        }

        try {
            $summary = $this->createDomain->handle($actor, $draft);
        } catch (DomainOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (DomainError $error) {
            // A value the contract let through and a value object refused —
            // a folder name, a quota. The name was already accepted above.
            // A name refused only here (the list transport domain) belongs
            // to the name field like any other invalid name.
            return $this->responder->invalid(
                $request,
                $error,
                $error->code() === DomainErrorCode::DOMAIN_INVALID_NAME ? 'body.name' : 'body',
            );
        }

        return $this->responder->created($request, $summary);
    }
}

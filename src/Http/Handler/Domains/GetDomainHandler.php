<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Domains;

use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Application\Domain\GetDomain;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /domains/{domain}`.
 *
 * The path segment goes through {@see DomainName::fromStoredValue()}, the
 * tolerant door, not the strict one. A domain that is already in the table may
 * be spelled in a way this application would refuse to create — a single label,
 * an underscore, mixed case — and it still has to be readable (U-22). Applying
 * the strict rule here would make exactly those domains unreachable through the
 * API that is supposed to let somebody clean them up.
 *
 * A segment that cannot be a domain name **at all** — empty, with a slash, 300
 * bytes long — is `404`. It is not a validation error: nothing is wrong with the
 * request other than that no such resource can exist.
 *
 * {@see \Mailadmin\Domain\Policy\ResourceNotVisible} is deliberately not caught.
 * `AuthorizationMiddleware` turns it into the same `404` (U-44), and letting it
 * travel is what keeps that decision in one place.
 */
final readonly class GetDomainHandler implements ApiHandler
{
    public function __construct(
        private GetDomain $getDomain,
        private DomainResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $name = DomainName::fromStoredValue(DomainPath::of($request));
        } catch (DomainError) {
            return $this->responder->notFound($request);
        }

        try {
            $summary = $this->getDomain->handle($actor, $name);
        } catch (DomainOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        }

        return $this->responder->domain($request, $summary);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Domains;

use Mailadmin\Application\Domain\DomainChange;
use Mailadmin\Application\Domain\DomainOperationFailed;
use Mailadmin\Application\Domain\UpdateDomain;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /domains/{domain}` — attributes, and the rename.
 *
 * The path segment is read tolerantly (an existing domain may be spelled in a
 * way this application would not issue) and the **new** name in the body
 * strictly (nothing forces anyone to create a second unconventional name). The
 * asymmetry is U-22 in two lines: carry what is there, do not add to it.
 *
 * The response carries a `Location` when the domain moved, because it did: the
 * URL the client just used no longer addresses anything.
 */
final readonly class UpdateDomainHandler implements ApiHandler
{
    public function __construct(
        private UpdateDomain $updateDomain,
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

        $change = DomainChange::fromRequest(ParsedBody::of($request));

        try {
            $summary = $this->updateDomain->handle(
                $actor,
                $name,
                $change,
                DomainResponder::ifMatch($request),
            );
        } catch (DomainOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (DomainError $error) {
            return $this->responder->invalid($request, $error, 'body');
        }

        return $this->responder->domain(
            $request,
            $summary,
            // Byte comparison, not `equals()`: a change of spelling is a rename
            // too, because Cyrus and `accountuser` store the exact bytes.
            withLocation: $summary->name()->toString() !== $name->toString(),
        );
    }
}

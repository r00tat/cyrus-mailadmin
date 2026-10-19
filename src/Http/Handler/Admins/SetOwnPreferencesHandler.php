<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\AdminPreferences;
use Mailadmin\Application\Admin\SetOwnPreferences;
use Mailadmin\Domain\Model\InterfaceLocale;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /admins/me/preferences` (W2).
 *
 * Request validation has already checked the body against `AdminPreferences`:
 * `locale` is present, and is either `null` or a value of `InterfaceLocale`. The
 * `tryFrom()` below is the second lock, for a contract and an enum that have
 * drifted apart — a contract test exists so that they cannot.
 */
final readonly class SetOwnPreferencesHandler implements ApiHandler
{
    public function __construct(
        private SetOwnPreferences $setOwnPreferences,
        private AdminResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $value */
        $value = ParsedBody::of($request)['locale'] ?? null;

        $locale = null;

        if ($value !== null) {
            $locale = is_string($value) ? InterfaceLocale::tryFrom($value) : null;

            if ($locale === null) {
                return $this->responder->unprocessable($request, 'This interface language is not offered.');
            }
        }

        return $this->responder->preferences(
            $this->setOwnPreferences->handle(AdminResponder::actorOf($request), new AdminPreferences($locale)),
        );
    }
}

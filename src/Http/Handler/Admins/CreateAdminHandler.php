<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\CreateAdmin;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\ParsedBody;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admins`.
 *
 * `201` with a `Location` built from the request path, so that it is right in
 * both deployment variants without this class knowing the mount prefix (U-25):
 * the request arrived at `…/admins`, and the new administrator is one segment
 * below it.
 *
 * The `ETag` of the created representation goes out with the `201`, so that a
 * client which follows up with a `PATCH` already has a validator and does not
 * have to `GET` first.
 */
final readonly class CreateAdminHandler implements ApiHandler
{
    public function __construct(
        private CreateAdmin $createAdmin,
        private AdminResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = ParsedBody::of($request);
        $role = AdminRole::tryFrom(ParsedBody::text($body, 'role'));

        if ($role === null) {
            // The contract declares an `enum` and validation runs in front of
            // this handler, so this is the second lock. Guessing a role would be
            // the one wrong answer available: guessing `superuser` grants
            // everything, guessing `domainmaster` writes an account whose scope
            // nobody asked for.
            return $this->responder->unprocessable($request, 'The role must be superuser or domainmaster.');
        }

        $username = ParsedBody::text($body, 'username');

        try {
            $admin = $this->createAdmin->handle(
                actor: AdminResponder::actorOf($request),
                username: $username,
                password: ParsedBody::text($body, 'password'),
                role: $role,
                domains: self::domains($body),
            );
        } catch (AdminOperationFailed $failure) {
            return $this->responder->failure($request, $failure);
        } catch (InvalidPassword $refused) {
            return $this->responder->invalidPassword($request, $refused);
        }

        return $this->responder->admin(
            $admin,
            201,
            rtrim($request->getUri()->getPath(), '/') . '/' . rawurlencode($admin->username),
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    public static function domains(array $body): array
    {
        /** @var mixed $value */
        $value = $body['domains'] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $domains = [];

        /** @var mixed $domain */
        foreach ($value as $domain) {
            if (is_string($domain)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }
}

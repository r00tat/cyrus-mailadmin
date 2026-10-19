<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Admins;

use Mailadmin\Application\Admin\ListAdmins;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admins`.
 *
 * The criteria are assembled by hand rather than through
 * {@see ListCriteria::fromQuery()}, for one reason: `role` arrives as
 * `superuser` or `domainmaster` and the column it filters is
 * `adminuser.type`, an integer. The translation belongs here, where the
 * contract's vocabulary is still in scope — a filter allowlist maps a parameter
 * to a column, not a word to a number.
 *
 * An unparseable `role` is dropped rather than reported. It cannot occur: the
 * contract declares an `enum` and request validation runs in front of every
 * handler. Treating the impossible case as "no filter" keeps this method free of
 * a second error path that no test could reach.
 */
final readonly class ListAdminsHandler implements ApiHandler
{
    public function __construct(
        private ListAdmins $listAdmins,
        private AdminResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        try {
            $criteria = new ListCriteria(
                page: self::number($query, 'page', 1),
                perPage: self::number($query, 'per_page', ListCriteria::DEFAULT_PER_PAGE),
                sort: self::text($query, 'sort'),
                order: SortDirection::fromRequest(self::text($query, 'order')),
                filters: self::filters($query),
            );
        } catch (CriteriaError) {
            // Unreachable while request validation runs in front of the
            // dispatcher — `page` and `per_page` carry their bounds in the
            // contract. Kept because the bound is a property of the criteria
            // object, not of the middleware chain, and `422` is the right answer
            // either way.
            return $this->responder->unprocessable($request, 'The query parameters are not usable for this listing.');
        }

        return $this->responder->collection(
            $this->listAdmins->handle(AdminResponder::actorOf($request), $criteria),
        );
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<string, scalar>
     */
    private static function filters(array $query): array
    {
        $filters = [];

        $username = self::text($query, 'username');
        if ($username !== null) {
            $filters['username'] = $username;
        }

        $role = AdminRole::tryFrom(self::text($query, 'role') ?? '');
        if ($role !== null) {
            $filters['role'] = $role->toLegacyType();
        }

        return $filters;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function text(array $query, string $name): ?string
    {
        /** @var mixed $value */
        $value = $query[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function number(array $query, string $name, int $fallback): int
    {
        $value = self::text($query, $name);

        return $value !== null && preg_match('/^\d+$/', $value) === 1 ? (int) $value : $fallback;
    }
}

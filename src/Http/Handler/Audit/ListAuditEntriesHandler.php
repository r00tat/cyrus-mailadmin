<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Application\Audit\AuditRecord;
use Mailadmin\Application\Audit\ListAuditTrail;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * `GET /audit`.
 *
 * Turns the query string into {@see ListCriteria} and renders the page. The two
 * things it does beyond that are the two the criteria object cannot do for
 * itself:
 *
 * **The default ordering.** `ListCriteria::fromQuery()` defaults to ascending,
 * which is right for a list of domains and wrong for a trail — page one would be
 * the oldest entries in the table, for ever. The contract declares `desc` as the
 * default for this operation (U-65); an OpenAPI default is documentation and does
 * not reach the parser, so it is applied here, and `AuditEndpointTest` compares
 * the two so they cannot drift.
 *
 * **The timestamps.** `since` and `until` arrive as RFC 3339 — the boundary
 * validates that (`format: date-time`), so a value that reaches this method
 * parses. They are rewritten to the `DATETIME` spelling the column uses,
 * in UTC, before they become filters; comparing `2026-08-15T10:00:00Z` against a
 * `DATETIME` would compare a string to a date and quietly match nothing.
 */
final readonly class ListAuditEntriesHandler implements ApiHandler
{
    public function __construct(
        private ListAuditTrail $trail,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);

        if (!$actor instanceof ActorContext) {
            throw new \LogicException(
                'ListAuditEntriesHandler ran without a verified actor. The authentication '
                . 'middleware must be in the dispatcher’s route-middleware chain.',
            );
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();

        $page = $this->trail->handle($actor, self::criteriaFrom($query));

        $body = json_encode(
            [
                'items' => array_map(
                    static fn (AuditRecord $record): array => $record->toRepresentation(),
                    $page->items,
                ),
                'meta' => $page->meta(),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $this->responses
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            // Not cached, and not only because it changes: a shared cache holding
            // a page of the audit trail would hand it to the next reader.
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($body));
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function criteriaFrom(array $query): ListCriteria
    {
        foreach (['since', 'until'] as $key) {
            /** @var mixed $value */
            $value = $query[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $query[$key] = self::toColumnValue($value);
            }
        }

        $criteria = ListCriteria::fromQuery($query);

        if (array_key_exists('order', $query)) {
            return $criteria;
        }

        return $criteria->withSort($criteria->sort, ListAuditTrail::defaultOrder());
    }

    /**
     * RFC 3339 in, `Y-m-d H:i:s` in UTC out.
     *
     * An unparsable value cannot reach here — request validation rejects it as
     * `422` — and is passed through unchanged rather than guessed at if it
     * somehow does. The filter then matches nothing, which is the honest answer
     * to a bound the database cannot read.
     */
    private static function toColumnValue(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return $value;
        }
    }
}

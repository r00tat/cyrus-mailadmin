<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualEntry;
use Mailadmin\Domain\Model\Virtual\VirtualEntryType;
use Mailadmin\Domain\Policy\AddressPolicy;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * `GET /domains/{domain}/addresses` — every `virtual` row of the domain,
 * classified, **including the ones that fit no pattern** (U-22, U-103).
 *
 * Paged in PHP rather than in SQL: the type of a row is decided by the
 * classifier, after the read (architecture 3.4), so neither the `type` filter
 * nor the `type` sort can be a column. A domain's rows number in the hundreds,
 * not the hundred thousands; reading them all is one indexed scan.
 *
 * **A name without a `domain` row is listed when rows still carry it.** That is
 * the cleanup U-99 waits for: leftovers of a deleted domain block its name from
 * being created again, and this is where they are seen and removed. A name with
 * neither a row nor a domain is `404`.
 */
final readonly class ListDomainAddresses
{
    /** The `sort` values the contract enumerates. The first is the default. */
    public const array SORTS = ['alias', 'type'];

    /** The filters the contract declares. */
    public const array FILTERS = ['type', 'alias'];

    public function __construct(
        private AddressReader $reader,
        private DomainStore $domains,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws CriteriaError
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, DomainName $domain, ListCriteria $criteria): AddressListing
    {
        AddressPolicy::requireListIn($actor, $domain);

        [$type, $alias] = self::filters($criteria);
        $sort = self::sort($criteria);

        $rows = $this->reader->ofDomain($domain);

        if ($rows->isEmpty() && !$this->domains->exists($domain)) {
            throw AddressOperationFailed::notFound('such domain');
        }

        $matching = array_values(array_filter(
            $rows->entries,
            static fn (VirtualEntry $entry): bool => ($type === null || $entry->type() === $type)
                && ($alias === null || str_contains(strtolower($entry->alias()), $alias)),
        ));

        usort($matching, static function (VirtualEntry $left, VirtualEntry $right) use ($sort, $criteria): int {
            $order = $sort === 'type' ? strcmp($left->type()->value, $right->type()->value) : 0;

            if ($order === 0) {
                $order = strcasecmp($left->alias(), $right->alias());
            }

            if ($order === 0) {
                $order = strcmp(EntryKey::of($left->row()), EntryKey::of($right->row()));
            }

            return $criteria->order === SortDirection::Descending ? -$order : $order;
        });

        return new AddressListing(
            Page::of(
                array_values(array_slice($matching, $criteria->offset(), $criteria->perPage)),
                $criteria,
                count($matching),
            ),
            $rows,
        );
    }

    /**
     * @return array{0: VirtualEntryType|null, 1: string|null}
     *
     * @throws CriteriaError
     */
    private static function filters(ListCriteria $criteria): array
    {
        $type = null;
        $alias = null;

        foreach ($criteria->filters as $key => $value) {
            if (!in_array($key, self::FILTERS, true)) {
                throw CriteriaError::unknownFilterField((string) $key, self::FILTERS);
            }

            if (!is_scalar($value)) {
                throw CriteriaError::filterValueNotScalar((string) $key);
            }

            if ($key === 'type') {
                $type = VirtualEntryType::tryFrom((string) $value)
                    ?? throw CriteriaError::unknownFilterField('type=' . (string) $value, self::FILTERS);
            } else {
                $alias = strtolower(trim((string) $value));
            }
        }

        return [$type, $alias === '' ? null : $alias];
    }

    /**
     * @throws CriteriaError
     */
    private static function sort(ListCriteria $criteria): string
    {
        $sort = $criteria->sort === null || trim($criteria->sort) === '' ? self::SORTS[0] : trim($criteria->sort);

        if (!in_array($sort, self::SORTS, true)) {
            throw CriteriaError::unknownSortField($sort, self::SORTS);
        }

        return $sort;
    }
}

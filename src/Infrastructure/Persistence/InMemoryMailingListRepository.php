<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\MailingList\MailingListName;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;

/**
 * `lists` in an array — the double the A5 use cases are tested against.
 *
 * It is not a simplification of {@see SqlMailingListRepository} but a second
 * implementation of the same promises, and the two that matter are checked here
 * as well as there:
 *
 *  - **The stored member text survives untouched.** {@see storedMembers()} hands
 *    the raw column value back, so a test can compare byte for byte what was put
 *    in with what came out — which is the acceptance criterion the package
 *    document names.
 *  - **An unknown sort or filter key is an error, never a silent default.** The
 *    real repository gets that from {@see Criteria\SortAllowlist}; a double that
 *    accepted anything would let a use-case test pass against a request the
 *    database would refuse.
 *
 * **Not wired into the container**, for the same reason S2's doubles are not
 * (U-37): a container that could be talked into an in-memory mailing list store
 * by an environment variable would be one variable away from an installation
 * that saves nothing. Tests construct it directly.
 */
final class InMemoryMailingListRepository implements MailingListRepository
{
    /** @var list<string> the sort keys this double accepts, as the SQL one does */
    private const array SORTABLE = ['name', 'tag', 'address'];

    /** @var list<string> likewise for filters */
    private const array FILTERABLE = ['name', 'tag', 'address', 'member'];

    /** @var array<string, MailingList> keyed by name, in insertion order */
    private array $lists = [];

    /** Statements that would have hit the database, for tests that count them. */
    private int $writes = 0;

    public function given(MailingList ...$lists): void
    {
        foreach ($lists as $list) {
            $this->lists[$list->name->toString()] = $list;
        }
    }

    /**
     * The raw `members` column of a stored list — what a byte-for-byte
     * comparison needs.
     */
    public function storedMembers(string $name): ?string
    {
        return ($this->lists[$name] ?? null)?->members->toStoredValue();
    }

    public function writeCount(): int
    {
        return $this->writes;
    }

    public function list(ActorContext $actor, ListCriteria $criteria): Page
    {
        $matching = array_values(array_filter(
            $this->scoped($actor),
            fn (MailingList $list): bool => $this->matches($list, $criteria),
        ));

        self::sort($matching, $criteria);

        return Page::of(
            array_slice($matching, $criteria->offset(), $criteria->perPage),
            $criteria,
            count($matching),
        );
    }

    public function find(MailingListName $name): ?MailingList
    {
        return $this->lists[$name->toString()] ?? null;
    }

    public function add(MailingList $list): bool
    {
        if (isset($this->lists[$list->name->toString()])) {
            return false;
        }

        ++$this->writes;
        $this->lists[$list->name->toString()] = $list;

        return true;
    }

    public function replace(MailingList $list): void
    {
        ++$this->writes;
        $this->lists[$list->name->toString()] = $list;
    }

    public function remove(MailingListName $name): bool
    {
        if (!isset($this->lists[$name->toString()])) {
            return false;
        }

        ++$this->writes;
        unset($this->lists[$name->toString()]);

        return true;
    }

    /**
     * @return list<MailingList>
     */
    private function scoped(ActorContext $actor): array
    {
        if (!$actor->requiresDomainScope()) {
            return array_values($this->lists);
        }

        return array_values(array_filter($this->lists, static function (MailingList $list) use ($actor): bool {
            $domain = $list->domain();

            return $domain !== null && $actor->canSee($domain);
        }));
    }

    /**
     * @throws CriteriaError
     */
    private function matches(MailingList $list, ListCriteria $criteria): bool
    {
        /** @var scalar|list<scalar> $value */
        foreach ($criteria->filters as $key => $value) {
            if (!in_array($key, self::FILTERABLE, true)) {
                throw CriteriaError::unknownFilterField($key, self::FILTERABLE);
            }

            if (is_array($value)) {
                throw CriteriaError::filterValueNotScalar($key);
            }

            $needle = (string) $value;

            $hit = match ($key) {
                'name' => str_contains($list->name->toString(), $needle),
                'tag' => $list->tag === $needle,
                'address' => str_contains($list->address, $needle),
                default => str_contains($list->members->toStoredValue() ?? '', $needle),
            };

            if (!$hit) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<MailingList> $lists
     *
     * @param-out list<MailingList> $lists
     *
     * @throws CriteriaError
     */
    private static function sort(array &$lists, ListCriteria $criteria): void
    {
        $key = $criteria->sort === null || trim($criteria->sort) === '' ? 'name' : trim($criteria->sort);

        if (!in_array($key, self::SORTABLE, true)) {
            throw CriteriaError::unknownSortField($key, self::SORTABLE);
        }

        $descending = $criteria->order === SortDirection::Descending;

        usort($lists, static function (MailingList $left, MailingList $right) use ($key, $descending): int {
            $comparison = strcmp(self::sortValue($left, $key), self::sortValue($right, $key));

            // The same tiebreaker the SQL repository declares: the primary key.
            if ($comparison === 0) {
                return strcmp($left->name->toString(), $right->name->toString());
            }

            return $descending ? -$comparison : $comparison;
        });
    }

    private static function sortValue(MailingList $list, string $key): string
    {
        return match ($key) {
            'tag' => $list->tag,
            'address' => $list->address,
            default => $list->name->toString(),
        };
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\DottedNamespace;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Throwable;

/**
 * `domain` in an array — what A1's use cases are tested against.
 *
 * The rule the mail-store double set (M2) applies here too: **it has to be as
 * strict as the real thing, not more forgiving.** A double that answers a sort
 * field the repository would reject turns a green suite into a promise nobody
 * keeps. So the allowlists are not reimplemented — they are
 * {@see DomainRepository::sortAllowlist()} and
 * {@see DomainRepository::filterAllowlist()}, the same objects, which means a
 * package that adds a sortable column cannot forget this file.
 *
 * The domain scope is applied the same way for the same reason: forgetting it
 * here would make every use-case test pass while the real listing leaked, which
 * is the exact failure U-45 is written about.
 *
 * It also does the thing a fake can do and a database cannot: it **records what
 * was asked of it** and can be told to fail. The rename compensation path in
 * {@see \Mailadmin\Application\Domain\RenameDomain} has no other way to be
 * tested — the interesting case is "the mailboxes moved and the database write
 * failed", and one cannot ask MariaDB for that on demand.
 *
 * **It is never wired into the container** (U-37, and the same reasoning as for
 * `InMemoryAdminDirectory`): an installation that could be talked into an
 * in-memory domain table by an environment variable would be one variable away
 * from accepting changes that go nowhere.
 */
final class InMemoryDomainStore implements DomainStore
{
    /** @var array<string, DomainSummary> lower-cased name => the domain */
    private array $domains = [];

    /** @var array<string, list<string>> lower-cased domain name => stored user names */
    private array $accounts = [];

    /** @var list<string> */
    private array $operations = [];

    private ?Throwable $renameFailure = null;

    private ?Throwable $existsFailure = null;

    /** @var list<string> `virtual.alias` values, as given */
    private array $aliases = [];

    /** @var list<string> `virtual.username` values, as given */
    private array $senderNames = [];

    /** @var list<array{admin: string, domain: string}> `domainadmin` rows */
    private array $assignments = [];

    /** @var (callable(): void)|null */
    private $beforeNamespaceLockedWork = null;

    private int $namespaceLockDepth = 0;

    private int $namespaceLocksTaken = 0;

    /**
     * Put a domain in the table, with the accounts it holds.
     */
    public function given(Domain $domain, string ...$accountUsernames): void
    {
        $key = self::key($domain->name);

        $this->domains[$key] = new DomainSummary($domain, count($accountUsernames));
        $this->accounts[$key] = array_values($accountUsernames);
    }

    /**
     * A row in `virtual` — an address, a catchall or a forward. A3's territory;
     * here only so that a deletion can see it.
     */
    public function givenAlias(string $alias): void
    {
        $this->aliases[] = $alias;
    }

    /** A row in `virtual` whose `username` is this value (U-110). */
    public function givenSenderName(string $username): void
    {
        $this->senderNames[] = $username;
    }

    /** A row in `domainadmin`. */
    public function givenAssignment(string $admin, string $domain): void
    {
        $this->assignments[] = ['admin' => $admin, 'domain' => $domain];
    }

    /**
     * The `domainadmin` rows that are left, as `admin => domain` pairs.
     *
     * @return list<array{admin: string, domain: string}>
     */
    public function assignments(): array
    {
        return $this->assignments;
    }

    /**
     * Run `$intrusion` once, right after the namespace lock is taken and before
     * the work under it — another request that got in between a courtesy check
     * and the lock.
     */
    public function beforeNamespaceLockedWork(callable $intrusion): void
    {
        $this->beforeNamespaceLockedWork = $intrusion;
    }

    /** How often {@see withNamespaceLock()} was entered. */
    public function namespaceLocksTaken(): int
    {
        return $this->namespaceLocksTaken;
    }

    /** Is the namespace lock held right now? */
    public function holdsNamespaceLock(): bool
    {
        return $this->namespaceLockDepth > 0;
    }

    /**
     * Make the next {@see rename()} fail — the case that leaves mailboxes moved
     * and the database untouched.
     */
    public function failOnRename(Throwable $failure): void
    {
        $this->renameFailure = $failure;
    }

    /** Make every {@see exists()} fail — a database that cannot be asked. */
    public function failOnExists(Throwable $failure): void
    {
        $this->existsFailure = $failure;
    }

    /**
     * What was asked of the store, in order.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function list(ActorContext $actor, ListCriteria $criteria): Page
    {
        $allowed = DomainRepository::sortAllowlist();
        $sortKey = $criteria->sort === null || trim($criteria->sort) === ''
            ? $allowed->defaultKey()
            : trim($criteria->sort);

        // Throws for a field the repository would not sort by, which is the
        // whole point of asking the same object.
        $allowed->column($sortKey);

        $filters = DomainRepository::filterAllowlist();
        $wanted = null;

        foreach ($criteria->filters as $key => $value) {
            $filters->compile([$key => $value]);

            if ($key === 'name' && is_scalar($value)) {
                $wanted = strtolower((string) $value);
            }
        }

        $matching = [];

        foreach ($this->domains as $summary) {
            if ($actor->requiresDomainScope() && !$actor->canSee($summary->name())) {
                continue;
            }

            if ($wanted !== null && !str_contains(strtolower($summary->name()->toString()), $wanted)) {
                continue;
            }

            $matching[] = $summary;
        }

        usort(
            $matching,
            static function (DomainSummary $left, DomainSummary $right) use ($sortKey, $criteria): int {
                $order = self::compare($left, $right, $sortKey);

                if ($order === 0) {
                    $order = strcasecmp($left->name()->toString(), $right->name()->toString());
                }

                return $criteria->order === SortDirection::Descending ? -$order : $order;
            },
        );

        $total = count($matching);
        $items = array_slice($matching, $criteria->offset(), $criteria->perPage);

        return Page::of(array_values($items), $criteria, $total);
    }

    public function find(DomainName $name): ?DomainSummary
    {
        return $this->domains[self::key($name)] ?? null;
    }

    public function exists(DomainName $name): bool
    {
        if ($this->existsFailure !== null) {
            throw $this->existsFailure;
        }

        return isset($this->domains[self::key($name)]);
    }

    public function prefixTaken(string $prefix): bool
    {
        foreach ($this->domains as $summary) {
            if (strcasecmp($summary->domain->legacyPrefix, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    public function create(Domain $domain): void
    {
        $this->operations[] = 'create ' . $domain->name->toString();
        $this->given($domain);
    }

    public function update(Domain $domain): void
    {
        $this->operations[] = 'update ' . $domain->name->toString();

        $key = self::key($domain->name);
        $current = $this->domains[$key] ?? null;

        $this->domains[$key] = new DomainSummary($domain, $current?->accountCount ?? 0);
    }

    public function delete(DomainName $name): array
    {
        $this->operations[] = 'delete ' . $name->toString();

        unset($this->domains[self::key($name)], $this->accounts[self::key($name)]);

        $removed = [];
        $kept = [];

        foreach ($this->assignments as $assignment) {
            if (strcasecmp($assignment['domain'], $name->toString()) === 0) {
                $removed[] = $assignment['admin'];
            } else {
                $kept[] = $assignment;
            }
        }

        $this->assignments = $kept;
        $removed = array_values(array_unique($removed));
        sort($removed);

        return $removed;
    }

    public function addressCount(DomainName $name): int
    {
        $suffix = '@' . strtolower($name->toString());

        return count(array_filter(
            $this->aliases,
            static fn (string $alias): bool => str_ends_with(strtolower($alias), $suffix),
        ));
    }

    public function namespaceClaimant(DomainName $name, ?DomainName $except = null): ?string
    {
        $suffix = '.' . strtolower($name->toString());

        foreach (DottedNamespace::parentsOf($name) as $parent) {
            if ($except !== null && $parent->equals($except)) {
                continue;
            }

            foreach ($this->accounts[self::key($parent)] ?? [] as $username) {
                if (!str_contains($username, '@') && str_ends_with(strtolower($username), $suffix)) {
                    return $username;
                }
            }
        }

        return null;
    }

    public function accountUsernameTaken(string $username, DomainName $except): bool
    {
        foreach ($this->accounts as $key => $usernames) {
            if ($key === self::key($except)) {
                continue;
            }

            foreach ($usernames as $stored) {
                if (strcasecmp($stored, $username) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function senderNameTaken(string $username): bool
    {
        foreach ($this->senderNames as $existing) {
            if (strcasecmp($existing, $username) === 0) {
                return true;
            }
        }

        return false;
    }

    public function aliasTaken(string $alias): bool
    {
        foreach ($this->aliases as $existing) {
            if (strcasecmp($existing, $alias) === 0) {
                return true;
            }
        }

        return false;
    }

    public function assignmentCount(DomainName $name): int
    {
        return count(array_filter(
            $this->assignments,
            static fn (array $assignment): bool => strcasecmp($assignment['domain'], $name->toString()) === 0,
        ));
    }

    /**
     * The `virtual.alias` values that are left.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function withNamespaceLock(callable $work): mixed
    {
        ++$this->namespaceLocksTaken;
        ++$this->namespaceLockDepth;

        try {
            if ($this->beforeNamespaceLockedWork !== null) {
                $intrusion = $this->beforeNamespaceLockedWork;
                $this->beforeNamespaceLockedWork = null;
                $intrusion();
            }

            return $work();
        } finally {
            --$this->namespaceLockDepth;
        }
    }

    public function accountUsernames(DomainName $domain): array
    {
        return $this->accounts[self::key($domain)] ?? [];
    }

    /**
     * No status table here; the call is recorded so that a test can see it
     * happens before {@see rename()} (U-129).
     */
    public function carryAccountStatus(array $renamedAccounts): void
    {
        $this->operations[] = 'carry status ' . implode(', ', array_map(
            static fn (string|int $old, string $new): string => $old . ' -> ' . $new,
            array_keys($renamedAccounts),
            $renamedAccounts,
        ));
    }

    public function rename(DomainName $from, DomainName $to, array $renamedAccounts): void
    {
        $this->operations[] = 'rename ' . $from->toString() . ' -> ' . $to->toString();

        if ($this->renameFailure !== null) {
            $failure = $this->renameFailure;
            $this->renameFailure = null;

            throw $failure;
        }

        $oldKey = self::key($from);
        $summary = $this->domains[$oldKey] ?? null;

        if ($summary === null) {
            return;
        }

        unset($this->domains[$oldKey]);

        $accounts = $this->accounts[$oldKey] ?? [];
        unset($this->accounts[$oldKey]);

        $renamed = [];

        foreach ($accounts as $stored) {
            $renamed[] = $renamedAccounts[$stored] ?? $stored;
        }

        // `virtual`: forward rows keyed by a moved user name, byte for byte,
        // then every alias ending in `@<from>` (U-101).
        $oldSuffix = '@' . strtolower($from->toString());
        $aliases = [];

        foreach ($this->aliases as $alias) {
            if (isset($renamedAccounts[$alias])) {
                $aliases[] = $renamedAccounts[$alias];
            } elseif (str_ends_with(strtolower($alias), $oldSuffix)) {
                $aliases[] = substr($alias, 0, -strlen($oldSuffix)) . '@' . $to->toString();
            } else {
                $aliases[] = $alias;
            }
        }

        $this->aliases = $aliases;

        foreach ($this->assignments as $index => $assignment) {
            if (strcasecmp($assignment['domain'], $from->toString()) === 0) {
                $this->assignments[$index]['domain'] = $to->toString();
            }
        }

        $newKey = self::key($to);
        $this->domains[$newKey] = new DomainSummary(
            $summary->domain->renamedTo($to),
            count($renamed),
        );
        $this->accounts[$newKey] = $renamed;
    }

    public function atomically(callable $work): mixed
    {
        // No rollback, and no pretence of one — the same choice as
        // `InMemoryAdminStore`. A double that simulated transactions would be
        // asserting its own implementation.
        return $work();
    }

    private static function compare(DomainSummary $left, DomainSummary $right, string $key): int
    {
        return match ($key) {
            'max_accounts' => $left->domain->maxAccounts->toLegacyValue()
                <=> $right->domain->maxAccounts->toLegacyValue(),
            'default_account_quota' => $left->domain->defaultAccountQuota->toLegacyKibibytes()
                <=> $right->domain->defaultAccountQuota->toLegacyKibibytes(),
            'domain_quota' => $left->domain->domainQuota->toLegacyKibibytes()
                <=> $right->domain->domainQuota->toLegacyKibibytes(),
            default => strcasecmp($left->name()->toString(), $right->name()->toString()),
        };
    }

    private static function key(DomainName $name): string
    {
        return strtolower($name->toString());
    }
}

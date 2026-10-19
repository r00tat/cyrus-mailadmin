<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * Would Postfix still deliver after this change? (U-112)
 *
 * {@see DestinationRules} refuses a destination that is the address itself.
 * A loop through two or more rows — `a → b`, `b → a`, or a forward from one
 * account to another whose forward points back — is only visible in the table
 * as a whole, so this class expands the rows the way Postfix's
 * `cleanup_map1n` does, with the change laid over what is stored:
 *
 *  - a key is looked up among the **enabled** rows; when an address has none,
 *    Postfix tries the domain's catchall `@domain`;
 *  - the first result replaces the address, the others are appended, and the
 *    replacement is looked up again;
 *  - an address that expands **into itself** (the `keep_copy` of a forward,
 *    an account's own address row) is marked and not expanded again — that is
 *    what ends every legitimate chain;
 *  - more than {@see RECURSION_LIMIT} steps on one position, or more than
 *    {@see EXPANSION_LIMIT} addresses, and Postfix defers the message with
 *    "unreasonable virtual_alias_maps map nesting/expansion". That is the loop.
 *
 * The limits are Postfix's defaults (`virtual_alias_recursion_limit`,
 * `virtual_alias_expansion_limit`). Lookups are cached for one check, so a loop
 * costs one query per distinct key, not one per step.
 *
 * A second question concerns the catchall alone (U-108): a destination in the
 * catchall's own domain must stay an enabled address. An address without an
 * enabled row falls back to the catchall, expands into itself and is
 * delivered to a mailbox that does not exist — the whole domain's unknown
 * mail bounces.
 */
final class LoopGuard
{
    /** `virtual_alias_recursion_limit`, Postfix's default. */
    public const int RECURSION_LIMIT = 1000;

    /** `virtual_alias_expansion_limit`, Postfix's default. */
    public const int EXPANSION_LIMIT = 1000;

    /** @var array<string, list<VirtualRow>> lower-cased alias => stored rows */
    private array $stored = [];

    /** @var list<VirtualRow> */
    private array $without = [];

    /** @var list<VirtualRow> */
    private array $with = [];

    public function __construct(private readonly AddressStore $store)
    {
    }

    /**
     * Refuse a write after which mail for `$origin` would go round.
     *
     * @param string           $origin  the key of the rows being written: an
     *                                  address, or an account's user name for
     *                                  its forward
     * @param list<VirtualRow> $without the rows the write removes
     * @param list<VirtualRow> $with    the rows the write adds
     *
     * @throws InvalidAddress naming the destination that leads into the loop
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function requireNoLoop(string $origin, array $without, array $with): void
    {
        $this->begin($without, $with);

        if ($this->delivers([$origin])) {
            return;
        }

        throw InvalidAddress::unusableDestination($this->blame($this->lookup($origin) ?? [$origin], $origin));
    }

    /**
     * What is wrong with the enabled catchall of `$domain` once the change is
     * made — a destination in its own domain that is no enabled address, or one
     * that leads into a loop — or `null` when nothing is (or there is no
     * enabled catchall).
     *
     * @param list<VirtualRow> $without
     * @param list<VirtualRow> $with
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function catchallProblem(DomainName $domain, array $without = [], array $with = []): ?string
    {
        $this->begin($without, $with);

        $destinations = $this->union($this->enabledRows(CatchallState::alias($domain)));

        if ($destinations === []) {
            return null;
        }

        foreach ($destinations as $destination) {
            if ($this->isDangling($domain, $destination)) {
                return $destination;
            }
        }

        if ($this->delivers($destinations)) {
            return null;
        }

        return $this->blame($destinations, null);
    }

    /**
     * Refuse a removal or a switch-off after which the domain's catchall no
     * longer delivers — but only when it did before: a legacy catchall that is
     * already broken does not block every other change in the domain.
     *
     * @param list<VirtualRow> $without
     * @param list<VirtualRow> $with
     *
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function requireCatchallKeepsWorking(DomainName $domain, string $address, array $without, array $with = []): void
    {
        if ($this->catchallProblem($domain) !== null) {
            return;
        }

        $problem = $this->catchallProblem($domain, $without, $with);

        if ($problem !== null) {
            throw AddressOperationFailed::neededByCatchall($address, $domain->toString(), $problem);
        }
    }

    /**
     * The domain of a stored alias or address, when it has one.
     */
    public static function domainOf(string $alias): ?DomainName
    {
        $at = strrpos($alias, '@');

        if ($at === false) {
            return null;
        }

        try {
            return DomainName::fromStoredValue(substr($alias, $at + 1));
        } catch (\Mailadmin\Domain\Exception\DomainError) {
            return null;
        }
    }

    // -----------------------------------------------------------------

    /**
     * @param list<VirtualRow> $without
     * @param list<VirtualRow> $with
     */
    private function begin(array $without, array $with): void
    {
        $this->without = $without;
        $this->with = $with;
        $this->stored = [];
    }

    /**
     * A destination in the catchall's own domain that Postfix would send back
     * through the catchall: no enabled row, and no account by that name.
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function isDangling(DomainName $domain, string $destination): bool
    {
        if (!EmailAddress::looksLikeAddress($destination)) {
            return false;
        }

        $own = self::domainOf($destination);

        if ($own === null || !$own->toLowerCase()->equals($domain->toLowerCase())) {
            return false;
        }

        return $this->enabledRows($destination) === [] && !$this->store->isAccountName($destination);
    }

    /**
     * The first of `$destinations` that loops on its own — the one to name in
     * the refusal. When none does alone, the first one.
     *
     * @param list<string> $destinations
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function blame(array $destinations, ?string $origin): string
    {
        foreach ($destinations as $destination) {
            if ($origin !== null && strcasecmp($destination, $origin) === 0) {
                continue;
            }

            if (!$this->delivers([$destination])) {
                return $destination;
            }
        }

        return $destinations[0] ?? (string) $origin;
    }

    /**
     * Postfix's expansion of `$recipients`: `false` when it ends in a
     * deferral.
     *
     * @param list<string> $recipients
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function delivers(array $recipients): bool
    {
        $argv = array_values($recipients);
        $beenHere = [];

        for ($arg = 0; $arg < count($argv); ++$arg) {
            if (count($argv) > self::EXPANSION_LIMIT) {
                return false;
            }

            for ($count = 0; ; ++$count) {
                if (isset($beenHere[strtolower($argv[$arg])])) {
                    break;
                }

                if ($count >= self::RECURSION_LIMIT) {
                    return false;
                }

                $lookup = $this->lookup($argv[$arg]);

                if ($lookup === null) {
                    break;
                }

                $lhs = $argv[$arg];

                foreach ($lookup as $index => $result) {
                    if ($index === 0) {
                        $argv[$arg] = $result;
                    } else {
                        $argv[] = $result;
                    }

                    // An address may expand into itself once.
                    if (strcasecmp($lhs, $result) === 0) {
                        $beenHere[strtolower($lhs)] = true;
                    }
                }
            }
        }

        return true;
    }

    /**
     * What `virtual_alias_maps` answers for `$key`: the destinations of its
     * enabled rows, or of the domain's enabled catchall when an address has
     * none; `null` when nothing answers.
     *
     * @return list<string>|null
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function lookup(string $key): ?array
    {
        $rows = $this->enabledRows($key);

        if ($rows === [] && str_contains($key, '@') && !str_starts_with($key, '@')) {
            $rows = $this->enabledRows(substr($key, (int) strrpos($key, '@')));
        }

        $destinations = $this->union($rows);

        return $destinations === [] ? null : $destinations;
    }

    /**
     * @param list<VirtualRow> $rows
     *
     * @return list<string>
     */
    private function union(array $rows): array
    {
        $all = [];

        foreach ($rows as $row) {
            // Postfix splits a result at commas and white space.
            foreach (preg_split('/[\s,]+/', $row->dest, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $destination) {
                $all[] = $destination;
            }
        }

        return $all;
    }

    /**
     * The enabled rows Postfix would read for `$key`, with the change laid
     * over what is stored.
     *
     * @return list<VirtualRow>
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function enabledRows(string $key): array
    {
        $cacheKey = strtolower($key);
        $this->stored[$cacheKey] ??= $this->store->rowsWithAlias($key);

        $rows = [];

        foreach ($this->stored[$cacheKey] as $row) {
            if ($row->isEnabled() && !$this->isRemoved($row)) {
                $rows[] = $row;
            }
        }

        foreach ($this->with as $row) {
            if ($row->isEnabled() && strcasecmp($row->alias, $key) === 0) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function isRemoved(VirtualRow $row): bool
    {
        foreach ($this->without as $removed) {
            if ($removed->equals($row)) {
                return true;
            }
        }

        return false;
    }
}

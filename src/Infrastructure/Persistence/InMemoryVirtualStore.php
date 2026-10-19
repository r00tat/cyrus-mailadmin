<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Throwable;

/**
 * `virtual`, and the user names of `accountuser`, in memory — for A3's
 * use-case and contract tests.
 *
 * **It behaves like the MyISAM table** (O-11): {@see atomically()} runs the
 * work and rolls nothing back. A test of an audit failure therefore sees what
 * production would see, and the set-back has to do the work.
 *
 * The rules {@see VirtualRepository} implements in SQL are implemented here in
 * PHP, from the same description in {@see AddressStore} — case-insensitive
 * `alias`, exact match for removal, the three ways a row belongs to a domain.
 * The integration test runs the same cases against MariaDB.
 *
 * Not wired in the container; tests construct it.
 */
final class InMemoryVirtualStore implements AddressStore
{
    /** @var list<VirtualRow> */
    private array $rows = [];

    /** @var array<string, DomainName> stored user name => domain */
    private array $accounts = [];

    /** @var list<string> */
    private array $operations = [];

    private ?Throwable $insertFailure = null;

    private ?Throwable $removeFailure = null;

    private ?Throwable $countFailure = null;

    public function givenAccount(string $storedUsername, string $domain): void
    {
        $this->accounts[$storedUsername] = DomainName::fromStoredValue($domain);
    }

    public function givenRow(string $alias, ?string $dest, string $username = '', int $status = 1): VirtualRow
    {
        $row = new VirtualRow($alias, $dest ?? '', $username, $status);
        $this->rows[] = $row;

        return $row;
    }

    /** Make the next {@see insert()} fail before it writes. */
    public function failNextInsert(Throwable $failure): void
    {
        $this->insertFailure = $failure;
    }

    /** Make the next {@see remove()} fail before it writes. */
    public function failNextRemove(Throwable $failure): void
    {
        $this->removeFailure = $failure;
    }

    /** Make every {@see count()} fail — the set-back's first step. */
    public function failCount(?Throwable $failure): void
    {
        $this->countFailure = $failure;
    }

    /**
     * @return list<VirtualRow>
     */
    public function all(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function rowsOfDomain(DomainName $domain): array
    {
        $suffix = strtolower('@' . $domain->toString());
        $ofDomain = [];
        $keysOfDomain = [];
        $allKeys = [];

        foreach ($this->accounts as $username => $accountDomain) {
            $key = strtolower((string) $username);
            $allKeys[$key] = true;

            if ($accountDomain->equals($domain)) {
                $ofDomain[(string) $username] = true;
                $keysOfDomain[$key] = true;
            }
        }

        return $this->sorted(array_filter(
            $this->rows,
            static function (VirtualRow $row) use ($suffix, $ofDomain, $keysOfDomain, $allKeys): bool {
                if (str_ends_with(strtolower($row->alias), $suffix)) {
                    return true;
                }

                if (str_contains($row->alias, '@')) {
                    return false;
                }

                $key = strtolower($row->alias);

                // 2. the key of an account of the domain (U-126);
                if (isset($keysOfDomain[$key])) {
                    return true;
                }

                // 3. a key of no account, with a sender of the domain.
                return $row->username !== ''
                    && isset($ofDomain[$row->username])
                    && !isset($allKeys[$key]);
            },
        ));
    }

    public function rowsWithAlias(string $alias): array
    {
        return $this->sorted(array_filter(
            $this->rows,
            static fn (VirtualRow $row): bool => strcasecmp($row->alias, $alias) === 0,
        ));
    }

    public function forwardRowsOf(string $storedUsername): array
    {
        return $this->sorted(array_filter(
            $this->rows,
            static fn (VirtualRow $row): bool => $row->alias === $storedUsername && $row->username === '',
        ));
    }

    public function accountDomains(array $usernames): array
    {
        $wanted = [];

        foreach ($usernames as $name) {
            $wanted[strtolower(trim($name))] = true;
        }

        $found = [];

        foreach ($this->accounts as $username => $domain) {
            if (isset($wanted[strtolower((string) $username)])) {
                $found[(string) $username] = $domain;
            }
        }

        return $found;
    }

    public function addressTaken(string $address): bool
    {
        return $this->rowsWithAlias($address) !== [] || $this->isAccountName($address);
    }

    public function isAccountName(string $name): bool
    {
        foreach (array_keys($this->accounts) as $username) {
            if (strcasecmp((string) $username, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function insert(VirtualRow $row): void
    {
        if ($this->insertFailure !== null) {
            $failure = $this->insertFailure;
            $this->insertFailure = null;

            throw $failure;
        }

        $this->rows[] = $row;
        $this->operations[] = 'insert ' . $row->alias;
    }

    public function remove(VirtualRow $row): bool
    {
        if ($this->removeFailure !== null) {
            $failure = $this->removeFailure;
            $this->removeFailure = null;

            throw $failure;
        }

        foreach ($this->rows as $index => $candidate) {
            if ($candidate->equals($row)) {
                unset($this->rows[$index]);
                $this->rows = array_values($this->rows);
                $this->operations[] = 'remove ' . $row->alias;

                return true;
            }
        }

        return false;
    }

    public function count(VirtualRow $row): int
    {
        if ($this->countFailure !== null) {
            throw $this->countFailure;
        }

        return count(array_filter($this->rows, static fn (VirtualRow $candidate): bool => $candidate->equals($row)));
    }

    public function atomically(callable $work): mixed
    {
        return $work();
    }

    /**
     * @param array<int, VirtualRow> $rows
     *
     * @return list<VirtualRow>
     */
    private function sorted(array $rows): array
    {
        $rows = array_values($rows);
        usort($rows, static fn (VirtualRow $left, VirtualRow $right): int => strcasecmp($left->alias, $right->alias));

        return $rows;
    }
}

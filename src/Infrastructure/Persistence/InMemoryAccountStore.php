<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Application\Account\AccountRecord;
use Mailadmin\Application\Account\AccountStatus;
use Mailadmin\Application\Account\AccountStore;
use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\PasswordFormatDetector;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Throwable;

/**
 * `accountuser` and the account rows of `virtual`, in memory, for A2's
 * use-case and contract tests.
 *
 * It asks {@see AccountRepository} for the sort and filter allowlists, so that a
 * test cannot use a criterion the real store would refuse. It keeps hashes,
 * because a test has to be able to show that the one written is bcrypt — and
 * it never hands them out except through {@see hashOf()}, which exists for
 * exactly that.
 *
 * Not wired in the container; tests construct it.
 */
final class InMemoryAccountStore implements AccountStore
{
    /**
     * @var array<string, array{record: AccountRecord, hash: string}> stored user name => row
     */
    private array $accounts = [];

    /** @var array<string, true> lower-cased `virtual.alias` values that are not account addresses */
    private array $foreignAliases = [];

    /** @var array<string, true> lower-cased `virtual.username` values of rows that are not an account's own */
    private array $senderNames = [];

    /** @var list<string> */
    private array $operations = [];

    private ?Throwable $createFailure = null;

    private ?Throwable $deleteFailure = null;

    /** Whether the simulated `allusers` honours the status (U-127). */
    private bool $viewHonoursStatus = true;

    /** @var (callable(): void)|null */
    private $beforeLockedWork = null;

    /**
     * Put an account in the table.
     */
    public function given(
        string $storedUsername,
        string $domain,
        string $hash = '$2y$04$abcdefghijklmnopqrstuuAbCdEfGhIjKlMnOpQrStUvWxYz01234',
        ?AccountServices $services = null,
        string $prefix = '',
        ?AccountStatus $status = null,
    ): AccountRecord {
        $record = AccountRecord::fromStored(
            $storedUsername,
            DomainName::fromStoredValue($domain),
            $services ?? AccountServices::all(),
            PasswordFormatDetector::detect($hash),
            $prefix,
            $status,
        );

        $this->accounts[$storedUsername] = ['record' => $record, 'hash' => $hash];

        return $record;
    }

    /** An alias row that is not an account address — A3's territory. */
    public function givenAlias(string $alias): void
    {
        $this->foreignAliases[strtolower($alias)] = true;
    }

    /**
     * A row in `virtual` whose `username` names this value — an alias's
     * sending account or its placeholder (U-106).
     */
    public function givenSenderName(string $username): void
    {
        $this->senderNames[strtolower($username)] = true;
    }

    /** Make the next {@see create()} fail, after nothing was written. */
    public function failOnCreate(Throwable $failure): void
    {
        $this->createFailure = $failure;
    }

    /**
     * Behave like an installation still running the view `allusers` without
     * the status filter: {@see offersLogin()} answers `true` for a disabled
     * account.
     */
    public function viewIgnoresStatus(): void
    {
        $this->viewHonoursStatus = false;
    }

    /** Make the next {@see delete()} fail, after nothing was removed. */
    public function failOnDelete(Throwable $failure): void
    {
        $this->deleteFailure = $failure;
    }

    /**
     * Run `$intrusion` after the lock is taken and before the locked work —
     * the moment a concurrent request would have to have acted.
     *
     * @param callable(): void $intrusion
     */
    public function beforeLockedWork(callable $intrusion): void
    {
        $this->beforeLockedWork = $intrusion;
    }

    public function hashOf(string $storedUsername): ?string
    {
        return $this->accounts[$storedUsername]['hash'] ?? null;
    }

    public function has(string $storedUsername): bool
    {
        return isset($this->accounts[$storedUsername]);
    }

    /**
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function list(DomainName $domain, ListCriteria $criteria): Page
    {
        $sorts = AccountRepository::sortAllowlist();
        $sortKey = $criteria->sort === null || trim($criteria->sort) === '' ? $sorts->defaultKey() : trim($criteria->sort);
        $sorts->column($sortKey);

        $filters = AccountRepository::filterAllowlist();
        $name = null;
        $format = null;

        foreach ($criteria->filters as $key => $value) {
            $filters->compile([$key => $value]);

            if ($key === 'name' && is_scalar($value)) {
                $name = strtolower((string) $value);
            }

            if ($key === 'password_format' && is_scalar($value)) {
                $format = (string) $value;
            }
        }

        $matching = [];

        foreach ($this->accounts as $row) {
            $record = $row['record'];

            if (strcasecmp($record->domain->toString(), $domain->toString()) !== 0) {
                continue;
            }

            if ($name !== null && !str_contains(strtolower($record->storedUsername), $name)) {
                continue;
            }

            if ($format !== null && $record->passwordFormat->value !== $format) {
                continue;
            }

            $matching[] = $record;
        }

        usort($matching, static function (AccountRecord $left, AccountRecord $right) use ($sortKey, $criteria): int {
            $order = $sortKey === 'password_format'
                ? strcmp($left->passwordFormat->value, $right->passwordFormat->value)
                : 0;

            if ($order === 0) {
                $order = strcmp($left->storedUsername, $right->storedUsername);
            }

            return $criteria->order === SortDirection::Descending ? -$order : $order;
        });

        return Page::of(
            array_values(array_slice($matching, $criteria->offset(), $criteria->perPage)),
            $criteria,
            count($matching),
        );
    }

    public function find(string $storedUsername): ?AccountRecord
    {
        return $this->accounts[$storedUsername]['record'] ?? null;
    }

    public function countIn(DomainName $domain): int
    {
        return count($this->usernamesIn($domain));
    }

    public function usernamesIn(DomainName $domain): array
    {
        $names = [];

        foreach ($this->accounts as $username => $row) {
            if (strcasecmp($row['record']->domain->toString(), $domain->toString()) === 0) {
                $names[] = (string) $username;
            }
        }

        sort($names);

        return $names;
    }

    public function isTaken(MailboxIdentity $identity): bool
    {
        foreach ($this->accounts as $username => $row) {
            if (strcasecmp((string) $username, $identity->cyrusUsername()) === 0) {
                return true;
            }

            $own = $row['record']->identity?->address()->toString();

            if ($own !== null && strcasecmp($own, $identity->address()->toString()) === 0) {
                return true;
            }
        }

        return isset($this->foreignAliases[strtolower($identity->address()->toString())])
            || isset($this->foreignAliases[strtolower($identity->cyrusUsername())])
            || isset($this->senderNames[strtolower($identity->cyrusUsername())]);
    }

    public function create(
        MailboxIdentity $identity,
        string $passwordHash,
        AccountServices $services,
        string $legacyPrefix,
    ): void {
        $this->operations[] = 'create ' . $identity->cyrusUsername();

        if ($this->createFailure !== null) {
            $failure = $this->createFailure;
            $this->createFailure = null;

            throw $failure;
        }

        $this->accounts[$identity->cyrusUsername()] = [
            'record' => new AccountRecord(
                $identity->cyrusUsername(),
                $identity->domain,
                $identity,
                $services,
                PasswordFormatDetector::detect($passwordHash),
                $legacyPrefix,
            ),
            'hash' => $passwordHash,
        ];
    }

    public function updateServices(string $storedUsername, AccountServices $services): void
    {
        $this->operations[] = 'updateServices ' . $storedUsername;

        if (isset($this->accounts[$storedUsername])) {
            $row = $this->accounts[$storedUsername];
            $this->accounts[$storedUsername] = ['record' => $row['record']->withServices($services), 'hash' => $row['hash']];
        }
    }

    public function setStatus(string $storedUsername, bool $enabled, string $changedBy): void
    {
        $this->operations[] = ($enabled ? 'enable ' : 'disable ') . $storedUsername;

        if (isset($this->accounts[$storedUsername])) {
            $row = $this->accounts[$storedUsername];
            $status = $enabled
                ? AccountStatus::enabled()
                : AccountStatus::disabled(new DateTimeImmutable('now', new DateTimeZone('UTC')), $changedBy);

            $this->accounts[$storedUsername] = ['record' => $row['record']->withStatus($status), 'hash' => $row['hash']];
        }
    }

    public function offersLogin(string $storedUsername, string $address): bool
    {
        $record = $this->accounts[$storedUsername]['record'] ?? null;

        if ($record === null) {
            return false;
        }

        return $record->status->enabled || !$this->viewHonoursStatus;
    }

    public function updatePasswordHash(string $storedUsername, string $passwordHash): void
    {
        $this->operations[] = 'updatePasswordHash ' . $storedUsername;

        if (isset($this->accounts[$storedUsername])) {
            $row = $this->accounts[$storedUsername];
            $this->accounts[$storedUsername] = [
                'record' => $row['record']->withPasswordFormat(PasswordFormatDetector::detect($passwordHash)),
                'hash' => $passwordHash,
            ];
        }
    }

    public function delete(string $storedUsername, DomainName $domain): int
    {
        $this->operations[] = 'delete ' . $storedUsername;

        if ($this->deleteFailure !== null) {
            $failure = $this->deleteFailure;
            $this->deleteFailure = null;

            throw $failure;
        }

        if (!isset($this->accounts[$storedUsername])) {
            return 0;
        }

        unset($this->accounts[$storedUsername]);

        return 1;
    }

    public function withDomainLock(DomainName $domain, callable $work): mixed
    {
        $this->operations[] = 'lock ' . $domain->toString();

        if ($this->beforeLockedWork !== null) {
            $intrusion = $this->beforeLockedWork;
            $this->beforeLockedWork = null;
            $intrusion();
        }

        return $work();
    }

    public function atomically(callable $work): mixed
    {
        // No rollback, and no pretence of one — the choice A1's double makes.
        return $work();
    }

    /**
     * The format a hash written by {@see create()} has — for a test asserting
     * that it is bcrypt without reading the hash.
     */
    public function formatOf(string $storedUsername): ?PasswordFormat
    {
        return $this->accounts[$storedUsername]['record']->passwordFormat ?? null;
    }
}

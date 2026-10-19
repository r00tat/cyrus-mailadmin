<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\AdminCredentials;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Access\DomainScope;
use Mailadmin\Domain\Exception\InvalidActorContext;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * `adminuser` and `domainadmin`, read through the port S2 defined.
 *
 * ## Case sensitivity is the table's, not ours
 *
 * `adminuser.username` is declared `latin1_bin`, so `=` is a byte comparison and
 * `Admin` is not `admin`. That is deliberately left alone: the legacy interface
 * behaves this way, the accounts in an existing installation were created with
 * it, and quietly folding case here would let two names that webcyradm treats as
 * different sign in as each other.
 *
 * ## The wildcard row
 *
 * webcyradm expresses "all domains" as a `domainadmin` row with `domain_name`
 * `*` (see {@see AdminRole::ALL_DOMAINS}). It is honoured for a domain master
 * too, not just for a superuser: an installation that has such a row means it,
 * and reading it as the literal domain name `*` would produce an administrator
 * who can see exactly one domain that does not exist.
 *
 * ## An unusable row is not an authentication failure that says so
 *
 * A domain master with no domain assigned, or with only unparsable domain names,
 * cannot act. {@see findActor()} answers `null`, the request becomes a `401`,
 * and the reason goes to the log — where an operator will find it — rather than
 * into the response, where it would tell an outsider that the account exists.
 */
final readonly class SqlAdminDirectory implements AdminDirectory
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function findCredentials(string $username): ?AdminCredentials
    {
        $row = $this->connection->selectOne(
            'SELECT `username`, `password`, `type` FROM `adminuser` WHERE `username` = :username',
            ['username' => $username],
        );

        if ($row === null) {
            return null;
        }

        return new AdminCredentials(
            username: self::text($row['username'] ?? null),
            passwordHash: self::text($row['password'] ?? null),
            role: AdminRole::fromLegacyType(self::number($row['type'] ?? null)),
        );
    }

    public function findActor(string $username): ?ActorContext
    {
        $row = $this->connection->selectOne(
            'SELECT `username`, `type` FROM `adminuser` WHERE `username` = :username',
            ['username' => $username],
        );

        if ($row === null) {
            return null;
        }

        $name = self::text($row['username'] ?? null);
        $role = AdminRole::fromLegacyType(self::number($row['type'] ?? null));

        try {
            return ActorContext::of($name, $role, $this->scopeOf($name));
        } catch (InvalidActorContext $error) {
            // A domain master with nothing assigned. Not an attack and not a
            // defect here — somebody removed the last row in `domainadmin`.
            $this->logger->warning('An administrator has no usable domain scope and cannot act.', [
                'admin' => $name,
                'reason' => $error->getMessage(),
            ]);

            return null;
        }
    }

    public function replacePasswordHash(string $username, #[SensitiveParameter] string $hash): void
    {
        $this->connection->execute(
            'UPDATE `adminuser` SET `password` = :hash WHERE `username` = :username',
            ['hash' => $hash, 'username' => $username],
        );
    }

    /**
     * A superuser's scope is not read at all: {@see ActorContext::of()} widens
     * it regardless, and asking the database for rows nobody will look at is a
     * query per request for nothing.
     */
    private function scopeOf(string $username): DomainScope
    {
        $rows = $this->connection->select(
            'SELECT `domain_name` FROM `domainadmin` WHERE `adminuser` = :username',
            ['username' => $username],
        );

        $domains = [];

        foreach ($rows as $row) {
            $name = trim(self::text($row['domain_name'] ?? null));

            if ($name === '') {
                continue;
            }

            if ($name === AdminRole::ALL_DOMAINS) {
                return DomainScope::all();
            }

            try {
                // The tolerant door: these names are already in the database and
                // webcyradm accepted them. Rejecting an unconventional one now
                // would take away a domain the mail stack is happily delivering.
                $domains[] = DomainName::fromStoredValue($name);
            } catch (InvalidAddress $error) {
                // A row webcyradm accepted that the value object does not. It is
                // skipped rather than fatal: one unparsable assignment must not
                // cost an administrator the domains that are fine.
                $this->logger->warning('A domainadmin row names something that is not a domain.', [
                    'admin' => $username,
                    'reason' => $error->getMessage(),
                ]);
            }
        }

        return DomainScope::of(...$domains);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

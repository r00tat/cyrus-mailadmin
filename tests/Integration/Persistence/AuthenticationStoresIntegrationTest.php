<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use DateTimeImmutable;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\SqlAdminDirectory;
use Mailadmin\Infrastructure\Persistence\SqlLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\SqlRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\SqlTokenDenylist;
use Mailadmin\Infrastructure\Security\RefreshTokenRecord;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * The four SQL implementations against a real MariaDB.
 *
 * The in-memory doubles carry the behavioural tests; this suite exists for the
 * things a double cannot be wrong about — the statements themselves, the
 * `ON DUPLICATE KEY` upsert, the `DATETIME` round trip, and the one property the
 * doubles can only imitate: that {@see SqlRefreshTokenStore::consume()} succeeds
 * exactly once because the database says so, not because PHP is single-threaded.
 *
 * Skips without a database. It never passes without one.
 */
#[CoversClass(SqlRefreshTokenStore::class)]
#[CoversClass(SqlTokenDenylist::class)]
#[CoversClass(SqlLoginAttemptLog::class)]
#[CoversClass(SqlAdminDirectory::class)]
final class AuthenticationStoresIntegrationTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock();

        $this->createLegacyBaseline();
        $this->applyOwnMigrations();
    }

    // -----------------------------------------------------------------
    // Refresh tokens
    // -----------------------------------------------------------------

    public function testATokenSurvivesTheRoundTripThroughTheTable(): void
    {
        $store = new SqlRefreshTokenStore($this->connection);
        $issued = $this->clock->now();
        $expires = $issued->setTimestamp($issued->getTimestamp() + 43200);

        $store->store(new RefreshTokenRecord(
            tokenHash: str_repeat('a', 64),
            family: str_repeat('f', 32),
            subject: 'anna',
            issuedAt: $issued,
            expiresAt: $expires,
        ));

        $found = $store->find(str_repeat('a', 64));

        self::assertNotNull($found);
        self::assertSame('anna', $found->subject);
        self::assertSame($issued->getTimestamp(), $found->issuedAt->getTimestamp());
        self::assertSame($expires->getTimestamp(), $found->expiresAt->getTimestamp());
        self::assertFalse($found->isConsumed());
        self::assertFalse($found->isRevoked());
    }

    /**
     * The security property, against the thing that actually enforces it. The
     * double can only show that the *rule* holds; this shows that the single
     * conditional `UPDATE` is what decides, which is what makes two concurrent
     * requests with the same token safe.
     */
    public function testATokenCanBeConsumedExactlyOnce(): void
    {
        $store = new SqlRefreshTokenStore($this->connection);
        $this->give($store, 'once');

        self::assertTrue($store->consume(self::hashOf('once'), $this->clock->now()));
        self::assertFalse($store->consume(self::hashOf('once'), $this->clock->now()));
    }

    public function testAnExpiredTokenCannotBeConsumed(): void
    {
        $store = new SqlRefreshTokenStore($this->connection);
        $this->give($store, 'stale');

        $this->clock->advance(43201);

        self::assertFalse($store->consume(self::hashOf('stale'), $this->clock->now()));
    }

    public function testRevokingAFamilyTouchesEveryTokenInItAndNothingElse(): void
    {
        $store = new SqlRefreshTokenStore($this->connection);
        $this->give($store, 'a', family: 'family-one');
        $this->give($store, 'b', family: 'family-one');
        $this->give($store, 'c', family: 'family-two');

        self::assertSame(2, $store->revokeFamily('family-one', $this->clock->now()));

        self::assertTrue($store->find(self::hashOf('a'))?->isRevoked());
        self::assertTrue($store->find(self::hashOf('b'))?->isRevoked());
        self::assertFalse($store->find(self::hashOf('c'))?->isRevoked());
    }

    public function testRevokingIsIdempotent(): void
    {
        $store = new SqlRefreshTokenStore($this->connection);
        $this->give($store, 'a', family: 'family-one');

        self::assertSame(1, $store->revokeFamily('family-one', $this->clock->now()));
        self::assertSame(0, $store->revokeFamily('family-one', $this->clock->now()));
    }

    /**
     * A consumed token has to stay recognisable until it expires — recognising
     * it is how a theft is caught. Only the deadline may prune it.
     */
    public function testPruningKeepsAConsumedButUnexpiredToken(): void
    {
        $store = new SqlRefreshTokenStore($this->connection);
        $this->give($store, 'spent');
        $store->consume(self::hashOf('spent'), $this->clock->now());

        self::assertSame(0, $store->prune($this->clock->now()));
        self::assertNotNull($store->find(self::hashOf('spent')));

        $this->clock->advance(43201);

        self::assertSame(1, $store->prune($this->clock->now()));
        self::assertNull($store->find(self::hashOf('spent')));
    }

    // -----------------------------------------------------------------
    // Denylist
    // -----------------------------------------------------------------

    public function testATokenAndItsSubjectAreBothAnsweredByOneTable(): void
    {
        $denylist = new SqlTokenDenylist($this->connection, $this->clock);

        $revoked = $this->claims('anna', 'jti-revoked');
        $other = $this->claims('anna', 'jti-other');

        $denylist->denyToken($revoked, TokenDenylist::REASON_LOGOUT);

        self::assertTrue($denylist->isDenied($revoked));
        self::assertFalse($denylist->isDenied($other));

        $denylist->denySubject(
            'anna',
            $this->clock->now(),
            $this->clock->now()->setTimestamp($this->clock->now()->getTimestamp() + 3600),
            TokenDenylist::REASON_ROLE_CHANGE,
        );

        self::assertTrue($denylist->isDenied($other));
    }

    /**
     * The upsert must widen and never narrow. `GREATEST` in SQL is what does it,
     * and getting that backwards would quietly un-revoke tokens on the second
     * call.
     */
    public function testASecondRevocationNeverMovesTheCutoffBackwards(): void
    {
        $denylist = new SqlTokenDenylist($this->connection, $this->clock);
        $late = $this->clock->now();
        $early = $late->setTimestamp($late->getTimestamp() - 7200);
        $expires = $late->setTimestamp($late->getTimestamp() + 3600);

        $denylist->denySubject('anna', $late, $expires, TokenDenylist::REASON_ROLE_CHANGE);
        $denylist->denySubject('anna', $early, $expires, TokenDenylist::REASON_ROLE_CHANGE);

        $caught = $this->claims('anna', 'jti-1', $late->setTimestamp($late->getTimestamp() - 60));

        self::assertTrue($denylist->isDenied($caught));
    }

    public function testSigningOutTwiceWritesOneRow(): void
    {
        $denylist = new SqlTokenDenylist($this->connection, $this->clock);
        $claims = $this->claims('anna', 'jti-1');

        $denylist->denyToken($claims, TokenDenylist::REASON_LOGOUT);
        $denylist->denyToken($claims, TokenDenylist::REASON_LOGOUT);

        self::assertSame(1, $this->countIn('mailadmin_token_denylist'));
    }

    // -----------------------------------------------------------------
    // Login attempts
    // -----------------------------------------------------------------

    public function testAttemptsAreCountedByNameAndByAddressWithinTheWindow(): void
    {
        $log = new SqlLoginAttemptLog($this->connection);
        $since = $this->clock->now()->setTimestamp($this->clock->now()->getTimestamp() - 900);

        $log->recordFailure('anna', '203.0.113.7', $this->clock->now());
        $log->recordFailure('anna', '203.0.113.7', $this->clock->now());
        $log->recordFailure('bruno', '203.0.113.7', $this->clock->now());

        self::assertSame(2, $log->countForUsername('anna', $since));
        self::assertSame(3, $log->countForClient('203.0.113.7', $since));
    }

    public function testAttemptsLeaveTheWindow(): void
    {
        $log = new SqlLoginAttemptLog($this->connection);

        $log->recordFailure('anna', '203.0.113.7', $this->clock->now());

        $future = $this->clock->now()->setTimestamp($this->clock->now()->getTimestamp() + 901);

        self::assertSame(0, $log->countForUsername('anna', $future));
    }

    public function testASuccessClearsTheName(): void
    {
        $log = new SqlLoginAttemptLog($this->connection);
        $since = $this->clock->now()->setTimestamp($this->clock->now()->getTimestamp() - 900);

        $log->recordFailure('anna', '203.0.113.7', $this->clock->now());
        $log->clearUsername('anna');

        self::assertSame(0, $log->countForUsername('anna', $since));
    }

    // -----------------------------------------------------------------
    // The administrator directory, against the legacy tables
    // -----------------------------------------------------------------

    public function testTheDirectoryReadsRoleAndScopeFromTheLegacyTables(): void
    {
        $this->pdo->exec(
            'INSERT INTO adminuser (username, password, type) VALUES ("bruno", "hash", 1)',
        );
        $this->pdo->exec(
            'INSERT INTO domainadmin (domain_name, adminuser) VALUES ("example.test", "bruno")',
        );

        $directory = new SqlAdminDirectory($this->connection, new NullLogger());

        $credentials = $directory->findCredentials('bruno');
        $actor = $directory->findActor('bruno');

        self::assertNotNull($credentials);
        self::assertSame(AdminRole::DomainMaster, $credentials->role);
        self::assertSame('hash', $credentials->passwordHash);

        self::assertNotNull($actor);
        self::assertSame(['example.test'], $actor->scope->domainNames());
    }

    /**
     * `adminuser.username` is `latin1_bin`, so `=` is a byte comparison. The
     * legacy interface behaves this way and folding case here would let two
     * names webcyradm treats as different sign in as each other.
     */
    public function testUserNamesAreComparedByteForByte(): void
    {
        $this->pdo->exec('INSERT INTO adminuser (username, password, type) VALUES ("anna", "hash", 0)');

        $directory = new SqlAdminDirectory($this->connection, new NullLogger());

        self::assertNotNull($directory->findCredentials('anna'));
        self::assertNull($directory->findCredentials('Anna'));
    }

    /**
     * webcyradm writes `*` for "every domain". Read as a literal domain name it
     * would produce an administrator who can see one domain that does not exist.
     */
    public function testTheWildcardRowMeansEveryDomain(): void
    {
        $this->pdo->exec('INSERT INTO adminuser (username, password, type) VALUES ("bruno", "hash", 1)');
        $this->pdo->exec('INSERT INTO domainadmin (domain_name, adminuser) VALUES ("*", "bruno")');

        $actor = (new SqlAdminDirectory($this->connection, new NullLogger()))->findActor('bruno');

        self::assertNotNull($actor);
        self::assertFalse($actor->requiresDomainScope());
    }

    public function testADomainMasterWithoutDomainsCannotAct(): void
    {
        $this->pdo->exec('INSERT INTO adminuser (username, password, type) VALUES ("bruno", "hash", 1)');

        self::assertNull((new SqlAdminDirectory($this->connection, new NullLogger()))->findActor('bruno'));
    }

    /**
     * Migration 0003 has to have run for this to be possible at all — an
     * Argon2id hash is about 97 characters and webcyradm declared the column
     * `VARCHAR(50)`.
     */
    public function testAnArgon2idHashFitsInTheWidenedColumn(): void
    {
        $this->pdo->exec('INSERT INTO adminuser (username, password, type) VALUES ("anna", "old", 0)');

        $hash = (string) password_hash('irrelevant', PASSWORD_ARGON2ID, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);

        $directory = new SqlAdminDirectory($this->connection, new NullLogger());
        $directory->replacePasswordHash('anna', $hash);

        self::assertSame($hash, $directory->findCredentials('anna')?->passwordHash);
    }

    // -----------------------------------------------------------------

    private function applyOwnMigrations(): void
    {
        $tables = ['mailadmin_refresh_token', 'mailadmin_token_denylist', 'mailadmin_login_attempt'];

        $this->dropIfPresent(...$tables);
        $this->willCreate(...$tables);

        $migrations = (new MigrationLoader(dirname(__DIR__, 3) . '/migrations'))->load();

        foreach ($migrations as $migration) {
            // 0001 and 0002 belong to F3 and are not needed here; 0003 is, because
            // an Argon2id hash does not fit the column without it.
            if ($migration->number !== 3 && $migration->number < 10) {
                continue;
            }

            foreach ($migration->statements as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }

    private function give(
        SqlRefreshTokenStore $store,
        string $token,
        string $family = 'family-one',
        string $subject = 'anna',
    ): void {
        $now = $this->clock->now();

        $store->store(new RefreshTokenRecord(
            tokenHash: self::hashOf($token),
            // CHAR(32) strips trailing spaces on the way out, so a short name
            // comes back exactly as it went in.
            family: $family,
            subject: $subject,
            issuedAt: $now,
            expiresAt: $now->setTimestamp($now->getTimestamp() + 43200),
        ));
    }

    private function claims(string $subject, string $jti, ?DateTimeImmutable $issuedAt = null): TokenClaims
    {
        $now = $issuedAt ?? $this->clock->now();

        return new TokenClaims(
            subject: $subject,
            role: AdminRole::Superuser,
            jti: $jti,
            issuedAt: $now,
            expiresAt: $now->setTimestamp($now->getTimestamp() + 3600),
        );
    }

    private function countIn(string $table): int
    {
        /** @var mixed $count */
        $count = $this->connection->selectValue('SELECT COUNT(*) FROM `' . $table . '`');

        return is_numeric($count) ? (int) $count : 0;
    }

    private static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}

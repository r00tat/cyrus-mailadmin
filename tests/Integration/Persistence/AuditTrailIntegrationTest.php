<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Persistence;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Audit\AuditRecord;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\Redactor;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Infrastructure\Persistence\AuditRepository;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\SqlAuditWriter;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `mailadmin_audit` against a real MariaDB.
 *
 * The double beside it carries the behavioural cases. What only a database can
 * be wrong about is here: the `INSERT` itself, the `DATETIME` round trip through
 * UTC, the JSON columns coming back as the arrays that went in, the ordering
 * with its tiebreaker, and the id that a batch's children are hung from.
 *
 * S4's definition of done is asserted against the **stored row**, not against
 * the object: "no entry contains a plaintext password or a hash" is a statement
 * about the table, and the only way to check a table is to read it.
 *
 * Skips without a database. It never passes without one.
 */
#[CoversClass(SqlAuditWriter::class)]
#[CoversClass(AuditRepository::class)]
final class AuditTrailIntegrationTest extends DatabaseTestCase
{
    private const string SECRET = 'correct-horse-battery-staple';

    private FrozenClock $clock;
    private AuditRequestContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock();
        $this->context = new AuditRequestContext();
        $this->context->bind('0123456789abcdef0123456789abcdef', '203.0.113.7');

        $this->applyOwnMigration();
    }

    public function testAnEntrySurvivesTheRoundTripThroughTheTable(): void
    {
        $this->writer()->record(AuditEntry::creation(
            self::superuser(),
            'domain.create',
            'domain',
            'example.test',
            after: ['name' => 'example.test', 'max_accounts' => 20, 'folders' => ['Sent', 'Trash']],
            domain: DomainName::fromString('example.test'),
        ));

        $records = $this->repository()->list(self::superuser(), new ListCriteria())->items;

        self::assertCount(1, $records);

        $entry = $records[0];

        self::assertSame('root', $entry->actor);
        self::assertSame('superuser', $entry->actorRole?->value);
        self::assertSame('203.0.113.7', $entry->clientIp);
        self::assertSame('0123456789abcdef0123456789abcdef', $entry->requestId);
        self::assertSame('domain.create', $entry->action);
        self::assertSame('domain', $entry->objectType);
        self::assertSame('example.test', $entry->objectId);
        self::assertSame('example.test', $entry->domain);
        self::assertSame(AuditOutcome::Succeeded, $entry->outcome);
        self::assertNull($entry->before);
        self::assertSame(
            ['name' => 'example.test', 'max_accounts' => 20, 'folders' => ['Sent', 'Trash']],
            $entry->after,
        );
        self::assertSame($this->clock->now()->getTimestamp(), $entry->recordedAt->getTimestamp());
    }

    /**
     * The column is UTC and carries no zone. Reading it back as local time would
     * shift every entry by the server's offset — invisible until an
     * investigation depends on the order of two events.
     */
    public function testTheTimestampGoesInAndComesOutAsUtc(): void
    {
        $this->writer()->record(AuditEntry::creation(self::superuser(), 'domain.create', 'domain', 'a.test'));

        $stored = (string) $this->connection->selectValue('SELECT `recorded_at` FROM `mailadmin_audit`');

        self::assertSame($this->clock->now()->format('Y-m-d H:i:s'), $stored);
    }

    public function testTheStoredRowContainsNeitherThePasswordNorTheHash(): void
    {
        $this->writer()->record(AuditEntry::change(
            self::superuser(),
            'account.password.write',
            'account',
            'anna@example.test',
            before: ['password_hash' => '$2y$10$usesomesillystringfore2uDLvp1Ii2e./U9C8sBjqp8I90dH6hi'],
            after: ['password' => self::SECRET, 'password_format' => 'bcrypt'],
        ));

        // Read with a statement of its own rather than through the repository:
        // the claim is about what is in the table, and a mapper could in
        // principle hide something the column holds.
        $stored = (string) $this->connection->selectValue(
            'SELECT CONCAT(COALESCE(`state_before`, \'\'), COALESCE(`state_after`, \'\')) FROM `mailadmin_audit`',
        );

        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertStringNotContainsString('$2y$', $stored);
        self::assertStringNotContainsString('password_hash', $stored);
        self::assertStringContainsString(Redactor::REDACTED, $stored);
        self::assertStringContainsString('bcrypt', $stored, 'The hash *format* is metadata and stays (U-22).');
    }

    public function testTheChildrenOfABatchPointAtTheOperation(): void
    {
        $writer = $this->writer();

        $operation = $writer->record(AuditEntry::change(
            self::superuser(),
            'domain.rename',
            'domain',
            'example.test',
            before: ['name' => 'example.test'],
            after: ['name' => 'renamed.test'],
        ));

        foreach (['a', 'b', 'c'] as $local) {
            $writer->record(
                AuditEntry::change(self::superuser(), 'account.rename', 'account', $local . '@example.test')
                    ->partOf($operation),
            );
        }

        $children = $this->repository()->list(
            self::superuser(),
            new ListCriteria(filters: ['part_of' => (string) $operation->value]),
        );

        self::assertSame(3, $children->total);
        self::assertSame($operation->value, $children->items[0]->parentId);
    }

    /**
     * Every row is written inside the same second, which is the ordinary case
     * for a batch. Without the `id` tiebreaker the server may return them in any
     * order and a row can appear on two pages.
     */
    public function testTheOrderingIsStableWithinOneSecond(): void
    {
        $writer = $this->writer();

        foreach (range(1, 5) as $index) {
            $writer->record(AuditEntry::creation(self::superuser(), 'domain.create', 'domain', 'd' . $index));
        }

        $first = $this->repository()->list(
            self::superuser(),
            new ListCriteria(perPage: 2, order: SortDirection::Ascending),
        );
        $second = $this->repository()->list(
            self::superuser(),
            new ListCriteria(page: 2, perPage: 2, order: SortDirection::Ascending),
        );

        self::assertSame(['d1', 'd2'], array_map(static fn (AuditRecord $r): string => $r->objectId, $first->items));
        self::assertSame(['d3', 'd4'], array_map(static fn (AuditRecord $r): string => $r->objectId, $second->items));
        self::assertSame(5, $first->total);
    }

    public function testTheTimeBoundsAreInclusiveAndComparedAsDates(): void
    {
        $writer = $this->writer();

        $writer->record(AuditEntry::creation(self::superuser(), 'domain.create', 'domain', 'early'));
        $middle = $this->clock->now();
        $this->clock->advance(3600);
        $writer->record(AuditEntry::creation(self::superuser(), 'domain.create', 'domain', 'late'));

        $since = $this->repository()->list(self::superuser(), new ListCriteria(
            filters: ['since' => $middle->modify('+1 second')->format('Y-m-d H:i:s')],
        ));

        self::assertSame(1, $since->total);
        self::assertSame('late', $since->items[0]->objectId);
    }

    public function testARefusalIsStoredWithItsReasonAndNoState(): void
    {
        $this->writer()->record(AuditEntry::refusal(
            self::superuser(),
            'domain.delete',
            'domain',
            'example.test',
            reason: 'the role does not hold domain.delete',
        ));

        $entry = $this->repository()->list(self::superuser(), new ListCriteria())->items[0];

        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertSame('the role does not hold domain.delete', $entry->reason);
        self::assertNull($entry->before);
        self::assertNull($entry->after);
    }

    public function testAnEntryWithoutADomainStoresNullRatherThanAnEmptyString(): void
    {
        $this->writer()->record(AuditEntry::creation(self::superuser(), 'session.sign_in', 'session', 'root'));

        $nulls = (int) $this->connection->selectValue(
            'SELECT COUNT(*) FROM `mailadmin_audit` WHERE `domain_name` IS NULL',
        );

        // The distinction the scoped listing depends on: `NULL` never satisfies
        // an `IN (…)`, an empty string might match a domain called ''.
        self::assertSame(1, $nulls);
    }

    public function testADomainMasterSeesOnlyTheirOwnDomains(): void
    {
        $writer = $this->writer();

        $writer->record(AuditEntry::creation(
            self::superuser(),
            'domain.update',
            'domain',
            'own.example',
            domain: 'own.example',
        ));
        $writer->record(AuditEntry::creation(
            self::superuser(),
            'domain.update',
            'domain',
            'other.example',
            domain: 'other.example',
        ));
        $writer->record(AuditEntry::creation(self::superuser(), 'session.sign_in', 'session', 'root'));

        $page = $this->repository()->list(
            ActorContext::domainMaster('master', DomainName::fromString('own.example')),
            new ListCriteria(),
        );

        self::assertSame(1, $page->total);
        self::assertSame('own.example', $page->items[0]->domain);
    }

    // -----------------------------------------------------------------

    private function writer(): SqlAuditWriter
    {
        return new SqlAuditWriter($this->connection, new Redactor(), $this->clock, $this->context);
    }

    private function repository(): AuditRepository
    {
        return new AuditRepository($this->connection);
    }

    private static function superuser(): ActorContext
    {
        return ActorContext::superuser('root');
    }

    private function applyOwnMigration(): void
    {
        $this->dropIfPresent('mailadmin_audit');
        $this->willCreate('mailadmin_audit');

        foreach ((new MigrationLoader(dirname(__DIR__, 3) . '/migrations'))->load() as $migration) {
            if ($migration->number !== 20) {
                continue;
            }

            foreach ($migration->statements as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }
}

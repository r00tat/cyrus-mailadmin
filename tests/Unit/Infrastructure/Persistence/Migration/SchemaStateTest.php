<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Infrastructure\Persistence\Migration\AppliedMigration;
use Mailadmin\Infrastructure\Persistence\Migration\Migration;
use Mailadmin\Infrastructure\Persistence\Migration\SchemaState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Recognising the state of the database — the unit half of the migrator's
 * behaviour. What actually runs the statements needs a server and lives in the
 * integration suite.
 */
#[CoversClass(SchemaState::class)]
#[CoversClass(AppliedMigration::class)]
final class SchemaStateTest extends TestCase
{
    /**
     * The normal case on the first run: a webcyradm database that has never seen
     * this application.
     */
    public function testAMissingVersionTableMeansUnmigrated(): void
    {
        $state = SchemaState::compare(false, [], [self::migration(1), self::migration(2)]);

        self::assertTrue($state->isUnmigrated());
        self::assertFalse($state->isUpToDate());
        self::assertSame(2, $state->pendingCount());
        self::assertNull($state->currentVersion());
        self::assertStringContainsString('no schema version table', $state->summary());
    }

    public function testEverythingAppliedMeansUpToDate(): void
    {
        $available = [self::migration(1), self::migration(2)];
        $applied = [self::applied(1), self::applied(2)];

        $state = SchemaState::compare(true, $applied, $available);

        self::assertTrue($state->isUpToDate());
        self::assertFalse($state->isUnmigrated());
        self::assertSame(2, $state->currentVersion());
        self::assertSame('up to date at 0002', $state->summary());
    }

    public function testOnlyTheUnappliedOnesArePending(): void
    {
        $state = SchemaState::compare(
            true,
            [self::applied(1)],
            [self::migration(1), self::migration(2), self::migration(10)],
        );

        self::assertSame(
            [2, 10],
            array_map(static fn (Migration $m): int => $m->number, $state->pending),
        );
        self::assertSame('at 0001, 2 migration(s) behind', $state->summary());
    }

    /**
     * A late-merged migration with a lower number than one that already ran is
     * still pending. That is why the version table records numbers rather than a
     * high-water mark (U-6).
     */
    public function testALowerNumberedMigrationThatArrivesLateIsStillPending(): void
    {
        $state = SchemaState::compare(
            true,
            [self::applied(10)],
            [self::migration(5), self::migration(10)],
        );

        self::assertSame([5], array_map(static fn (Migration $m): int => $m->number, $state->pending));
    }

    public function testAMigrationRecordedButNotShippedIsReported(): void
    {
        $state = SchemaState::compare(true, [self::applied(1), self::applied(99)], [self::migration(1)]);

        self::assertTrue($state->isUpToDate());
        self::assertSame(
            [99],
            array_map(static fn (AppliedMigration $m): int => $m->version, $state->unknown),
        );
    }

    /**
     * A file that changed after it ran is reported, never re-run: re-running it
     * is exactly what the additive rule forbids.
     */
    public function testAModifiedFileIsReportedRatherThanRepeated(): void
    {
        $state = SchemaState::compare(
            true,
            [new AppliedMigration(1, 'a', 'a-different-checksum', self::when(), 3)],
            [self::migration(1)],
        );

        self::assertTrue($state->isUpToDate());
        self::assertSame([1], array_map(static fn (Migration $m): int => $m->number, $state->modified));
    }

    public function testTheAppliedListIsOrderedEvenIfTheDatabaseWasNot(): void
    {
        $state = SchemaState::compare(true, [self::applied(10), self::applied(2)], []);

        self::assertSame(
            [2, 10],
            array_map(static fn (AppliedMigration $m): int => $m->version, $state->applied),
        );
        self::assertSame(10, $state->currentVersion());
    }

    public function testTheLatestAvailableVersionCountsPendingOnesToo(): void
    {
        $state = SchemaState::compare(true, [self::applied(1)], [self::migration(1), self::migration(7)]);

        self::assertSame(7, $state->latestAvailableVersion());
    }

    public function testAppliedMigrationReadsARowFromTheVersionTable(): void
    {
        $entry = AppliedMigration::fromRow([
            'version' => '3',
            'name' => 'audit',
            'checksum' => str_repeat('a', 64),
            'applied_at' => '2026-07-26 14:30:00',
            'duration_ms' => '12',
        ]);

        self::assertSame(3, $entry->version);
        self::assertSame('0003', $entry->formattedNumber());
        self::assertSame(12, $entry->durationMs);
        // U-14: the column holds UTC and is read as UTC.
        self::assertSame('2026-07-26T14:30:00+00:00', $entry->appliedAt->format('c'));
    }

    private static function migration(int $number): Migration
    {
        return Migration::fromFile(
            sprintf('%04d_thing.sql', $number),
            sprintf('CREATE TABLE IF NOT EXISTS mailadmin_thing_%d (id INT);', $number),
        );
    }

    private static function applied(int $number): AppliedMigration
    {
        return new AppliedMigration(
            $number,
            'thing',
            self::migration($number)->checksum,
            self::when(),
            1,
        );
    }

    private static function when(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
    }
}

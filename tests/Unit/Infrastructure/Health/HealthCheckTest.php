<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Health;

use Mailadmin\Application\Maintenance\HealthComponent;
use Mailadmin\Application\Maintenance\HealthMonitor;
use Mailadmin\Application\Maintenance\HealthProbes;
use Mailadmin\Application\Maintenance\HealthReport;
use Mailadmin\Application\Maintenance\ProbeResult;
use Mailadmin\Application\Maintenance\ProbeStatus;
use Mailadmin\Infrastructure\Health\FileHealthReportCache;
use Mailadmin\Infrastructure\Health\TimedProbe;
use Mailadmin\Infrastructure\Health\UncachedHealthReports;
use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Runtime\CacheDirectory;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The health check (U-121): probes that never throw, a report that says
 * `degraded` when one fails, and a cache that keeps `/health` from hammering
 * the backends.
 */
final class HealthCheckTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mailadmin-health-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testAProbeThatPassesIsOk(): void
    {
        $result = (new TimedProbe(HealthComponent::Database, static function (): void {
        }))->probe();

        self::assertSame(ProbeStatus::Ok, $result->status);
        self::assertNull($result->code);
        self::assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function testAMailStoreFailureKeepsItsCode(): void
    {
        $result = (new TimedProbe(HealthComponent::Imap, static function (): void {
            throw ConnectionFailed::socket('imap.invalid:993', 'refused');
        }))->probe();

        self::assertSame(ProbeStatus::Error, $result->status);
        self::assertSame(ConnectionFailed::socket('x', 'y')->errorCode(), $result->code);
    }

    public function testADatabaseFailureKeepsItsCode(): void
    {
        $result = (new TimedProbe(HealthComponent::Database, static function (): void {
            throw PersistenceError::configuration('no database configured');
        }))->probe();

        self::assertSame(ProbeStatus::Error, $result->status);
        self::assertSame(PersistenceError::configuration('x')->errorCode(), $result->code);
    }

    public function testAnythingElseIsUnavailableAndItsMessageStaysInside(): void
    {
        $result = (new TimedProbe(HealthComponent::ManageSieve, static function (): void {
            throw new RuntimeException('secret detail /etc/ssl/private');
        }))->probe();

        self::assertSame(TimedProbe::UNAVAILABLE, $result->code);
        self::assertStringNotContainsString('secret', (string) json_encode($result->toDetailed()));
    }

    public function testOneFailingComponentDegradesTheReport(): void
    {
        $monitor = new HealthMonitor(
            new HealthProbes(
                new TimedProbe(HealthComponent::Database, static function (): void {
                }),
                new TimedProbe(HealthComponent::Imap, static function (): void {
                    throw new RuntimeException('down');
                }),
            ),
            new UncachedHealthReports(),
            new FrozenClock(),
        );

        $report = $monitor->fresh();

        self::assertFalse($report->isHealthy());
        self::assertSame(
            ['status' => 'degraded', 'checks' => [
                ['component' => 'database', 'status' => 'ok'],
                ['component' => 'imap', 'status' => 'error'],
            ]],
            $report->toPublic(),
        );
        self::assertSame('2026-01-01T12:00:00Z', $report->toDetailed()['checked_at']);
    }

    public function testSkippedComponentsDoNotDegrade(): void
    {
        $report = new HealthReport((new FrozenClock())->now(), [ProbeResult::skipped(HealthComponent::ManageSieve)]);

        self::assertTrue($report->isHealthy());
        self::assertSame('ok', $report->status());
    }

    public function testTheCacheAnswersFromTheFileWithinItsWindow(): void
    {
        $clock = new FrozenClock();
        $cache = new FileHealthReportCache(new CacheDirectory($this->directory), $clock, 10);
        $calls = 0;
        $compute = static function () use (&$calls, $clock): HealthReport {
            ++$calls;

            return new HealthReport($clock->now(), [ProbeResult::error(HealthComponent::Imap, 'mailstore.unavailable', 12)]);
        };

        $first = $cache->remember($compute);
        $clock->advance(9);
        $second = $cache->remember($compute);

        self::assertSame(1, $calls);
        self::assertEquals($first->toDetailed(), $second->toDetailed());

        $clock->advance(1);
        $cache->remember($compute);

        self::assertSame(2, $calls, 'After the window the probes run again.');
    }

    public function testAnUnreadableCacheFileMeansNotCached(): void
    {
        file_put_contents($this->directory . '/' . FileHealthReportCache::FILE, '{not json');
        $cache = new FileHealthReportCache(new CacheDirectory($this->directory), new FrozenClock());

        $report = $cache->remember(static fn (): HealthReport => new HealthReport(
            (new FrozenClock())->now(),
            [ProbeResult::ok(HealthComponent::Database)],
        ));

        self::assertTrue($report->isHealthy());
    }

    public function testWithoutAUsableCacheDirectoryNothingIsProbedAndTheAnswerFailsClosed(): void
    {
        $cache = new FileHealthReportCache(new CacheDirectory($this->directory . '/missing'), new FrozenClock());
        $calls = 0;

        $report = $cache->remember(static function () use (&$calls): HealthReport {
            ++$calls;

            return new HealthReport((new FrozenClock())->now(), [ProbeResult::ok(HealthComponent::Database)]);
        });

        self::assertSame(0, $calls, 'An anonymous request must not log in to Cyrus when no round can be shared.');
        self::assertSame('degraded', $report->status());
        self::assertCount(count(HealthComponent::cases()), $report->checks);
        self::assertSame(TimedProbe::UNAVAILABLE, $report->checks[0]->code);
    }

    public function testAReportFromTheFutureIsNotFresh(): void
    {
        $clock = new FrozenClock();
        $this->writeCacheFile($clock->now()->getTimestamp() + 3600, 'ok');
        $cache = new FileHealthReportCache(new CacheDirectory($this->directory), $clock, 10);
        $calls = 0;

        $report = $cache->remember(static function () use (&$calls, $clock): HealthReport {
            ++$calls;

            return new HealthReport($clock->now(), [ProbeResult::error(HealthComponent::Imap, 'mailstore.unavailable')]);
        });

        self::assertSame(1, $calls, 'A clock stepped back must not keep an old "ok" alive.');
        self::assertSame('degraded', $report->status());
    }

    public function testWhileAnotherRequestProbesTheStaleReportIsServed(): void
    {
        $clock = new FrozenClock();
        $this->writeCacheFile($clock->now()->getTimestamp() - 60, 'error');
        $cache = new FileHealthReportCache(new CacheDirectory($this->directory), $clock, 10);
        $other = $this->holdLock();
        $calls = 0;

        try {
            $report = $cache->remember(static function () use (&$calls, $clock): HealthReport {
                ++$calls;

                return new HealthReport($clock->now(), [ProbeResult::ok(HealthComponent::Database)]);
            });
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        self::assertSame(0, $calls, 'Only the request holding the lock probes.');
        self::assertSame(HealthComponent::Imap, $report->checks[0]->component, 'The report in the file, however old.');
        self::assertSame(ProbeStatus::Error, $report->checks[0]->status);
    }

    public function testWithoutAnyReportARequestWaitsForTheRoundAndNeverProbesItself(): void
    {
        $clock = new FrozenClock();
        $cache = new FileHealthReportCache(new CacheDirectory($this->directory), $clock, 10, waitSeconds: 0.1);
        $other = $this->holdLock();
        $calls = 0;

        try {
            $report = $cache->remember(static function () use (&$calls, $clock): HealthReport {
                ++$calls;

                return new HealthReport($clock->now(), [ProbeResult::ok(HealthComponent::Database)]);
            });
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        self::assertSame(0, $calls);
        self::assertSame('degraded', $report->status(), 'No report and no round finished in time: fail closed.');
    }

    public function testAFailedWriteLeavesNoTemporaryFileBehind(): void
    {
        // A directory where the file belongs: the rename cannot replace it.
        $blocked = $this->directory . '/' . FileHealthReportCache::FILE;
        mkdir($blocked);
        touch($blocked . '/keep');
        $cache = new FileHealthReportCache(new CacheDirectory($this->directory), new FrozenClock());

        try {
            $report = $cache->remember(static fn (): HealthReport => new HealthReport(
                (new FrozenClock())->now(),
                [ProbeResult::ok(HealthComponent::Database)],
            ));

            self::assertSame('ok', $report->status());
            self::assertSame([], glob($blocked . '.*') ?: []);
        } finally {
            unlink($blocked . '/keep');
            rmdir($blocked);
        }
    }

    /**
     * @return resource
     */
    private function holdLock()
    {
        $handle = fopen($this->directory . '/' . FileHealthReportCache::LOCK, 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        return $handle;
    }

    private function writeCacheFile(int $checkedAt, string $imapStatus): void
    {
        file_put_contents($this->directory . '/' . FileHealthReportCache::FILE, json_encode([
            'checked_at' => $checkedAt,
            'checks' => [
                ['component' => 'imap', 'status' => $imapStatus, 'code' => null, 'duration_ms' => 1],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}

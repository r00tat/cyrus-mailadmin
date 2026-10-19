<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Health;

use DateTimeImmutable;
use Mailadmin\Application\Maintenance\HealthComponent;
use Mailadmin\Application\Maintenance\HealthReport;
use Mailadmin\Application\Maintenance\HealthReportCache;
use Mailadmin\Application\Maintenance\ProbeResult;
use Mailadmin\Application\Maintenance\ProbeStatus;
use Mailadmin\Runtime\CacheDirectory;
use Mailadmin\Runtime\Clock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The public health answer in a file in the cache directory, for a few seconds
 * (U-121).
 *
 * A file because PHP keeps nothing between requests and this application has
 * no shared cache (E-12). The file holds component, status and code — nothing a
 * reader of the cache directory could not also get from `/health`.
 *
 * ## One round of probes at a time (U-123)
 *
 * Every round is an administrator login at Cyrus, and `/health` needs no
 * token. A window alone limits only callers that arrive one after another:
 * every request that finds the file stale while a slow round is still running
 * would start a round of its own. So refreshing is single-flight — a
 * non-blocking `flock()` on {@see LOCK}. Whoever gets it runs the probes;
 * everybody else answers with the report in the file, however old. Only when
 * there is no report at all yet does a request wait for the round in progress,
 * for at most {@see DEFAULT_WAIT_SECONDS}, and it never probes itself.
 *
 * **Without the lock the answer fails closed.** If the lock file cannot be
 * opened, no request may probe — that would hand every anonymous caller an
 * administrator login. The answer is then every component `error` with
 * `request.service_unavailable`, `503`, and a warning in the log. Startup
 * already refuses a cache directory that is missing or not writable (U-13), so
 * this is the path of an unusual failure, not of a configuration mistake.
 *
 * A report is fresh while `0 <= age < seconds`. One from the future — a clock
 * stepped back, a directory shared with a skewed host — is not.
 */
final readonly class FileHealthReportCache implements HealthReportCache
{
    public const string FILE = 'health.json';

    public const string LOCK = 'health.lock';

    public const int DEFAULT_SECONDS = 10;

    /** Longer than one round with every probe running into its timeout. */
    public const float DEFAULT_WAIT_SECONDS = 15.0;

    private const int POLL_MICROSECONDS = 50_000;

    public function __construct(
        private CacheDirectory $directory,
        private Clock $clock,
        private int $seconds = self::DEFAULT_SECONDS,
        private LoggerInterface $logger = new NullLogger(),
        private float $waitSeconds = self::DEFAULT_WAIT_SECONDS,
    ) {
    }

    public function remember(callable $compute): HealthReport
    {
        $cached = $this->read();

        if ($cached !== null && $this->isFresh($cached)) {
            return $cached;
        }

        $lock = $this->openLock();

        if ($lock === null) {
            return $cached ?? $this->unavailable();
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                // Another request is probing right now.
                return $cached ?? $this->awaitRound($lock);
            }

            // The round that held the lock before may have just written.
            $again = $this->read();

            if ($again !== null && $this->isFresh($again)) {
                return $again;
            }

            $report = $compute();
            $this->write($report);

            return $report;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function isFresh(HealthReport $report): bool
    {
        $age = $this->clock->now()->getTimestamp() - $report->checkedAt->getTimestamp();

        return $age >= 0 && $age < $this->seconds;
    }

    /**
     * @return resource|null
     */
    private function openLock()
    {
        try {
            $lock = @fopen($this->directory->file(self::LOCK), 'c');
        } catch (Throwable) {
            $lock = false;
        }

        if ($lock === false) {
            $this->logger->warning(
                'The health cache lock cannot be opened; /health answers unavailable without probing.',
                ['file' => self::LOCK],
            );

            return null;
        }

        return $lock;
    }

    /**
     * No report yet and a round in progress: wait for it, never probe.
     *
     * @param resource $lock
     */
    private function awaitRound($lock): HealthReport
    {
        $deadline = microtime(true) + $this->waitSeconds;

        while (!flock($lock, LOCK_SH | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                return $this->unavailable();
            }

            usleep(self::POLL_MICROSECONDS);
        }

        return $this->read() ?? $this->unavailable();
    }

    /**
     * Every component `error`, nothing probed — the fail-closed answer.
     */
    private function unavailable(): HealthReport
    {
        return new HealthReport(
            $this->clock->now(),
            array_map(
                static fn (HealthComponent $component): ProbeResult => ProbeResult::error($component, TimedProbe::UNAVAILABLE),
                HealthComponent::cases(),
            ),
        );
    }

    private function read(): ?HealthReport
    {
        try {
            $path = $this->directory->file(self::FILE);

            if (!is_file($path)) {
                return null;
            }

            $content = file_get_contents($path);

            if ($content === false) {
                return null;
            }

            /** @var mixed $data */
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($data) || !is_int($data['checked_at'] ?? null) || !is_array($data['checks'] ?? null)) {
                return null;
            }

            $checks = [];

            /** @var mixed $check */
            foreach ($data['checks'] as $check) {
                if (!is_array($check)) {
                    return null;
                }

                $component = HealthComponent::tryFrom(is_string($check['component'] ?? null) ? $check['component'] : '');
                $status = ProbeStatus::tryFrom(is_string($check['status'] ?? null) ? $check['status'] : '');

                if ($component === null || $status === null) {
                    return null;
                }

                $checks[] = new ProbeResult(
                    $component,
                    $status,
                    is_string($check['code'] ?? null) ? $check['code'] : null,
                    is_int($check['duration_ms'] ?? null) ? $check['duration_ms'] : 0,
                );
            }

            return new HealthReport((new DateTimeImmutable())->setTimestamp($data['checked_at']), $checks);
        } catch (Throwable) {
            return null;
        }
    }

    private function write(HealthReport $report): void
    {
        try {
            $path = $this->directory->file(self::FILE);
            $temporary = $path . '.' . bin2hex(random_bytes(4));

            $content = json_encode(
                [
                    'checked_at' => $report->checkedAt->getTimestamp(),
                    'checks' => array_map(static fn (ProbeResult $check): array => $check->toDetailed(), $report->checks),
                ],
                JSON_THROW_ON_ERROR,
            );

            if (@file_put_contents($temporary, $content) === false) {
                @unlink($temporary);

                return;
            }

            // Atomic on one file system: a concurrent reader sees the old
            // file or the new one, never half of either.
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
            }
        } catch (Throwable) {
            // Not cached, then. The answer itself is unaffected.
        }
    }
}

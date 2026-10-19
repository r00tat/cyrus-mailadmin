<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The result of one round of probes.
 */
final readonly class HealthReport
{
    /**
     * @param list<ProbeResult> $checks
     */
    public function __construct(
        public DateTimeImmutable $checkedAt,
        public array $checks,
    ) {
    }

    /**
     * No probe answered `error`. A `skipped` probe does not make the service
     * unhealthy — it was not asked.
     */
    public function isHealthy(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status === ProbeStatus::Error) {
                return false;
            }
        }

        return true;
    }

    public function status(): string
    {
        return $this->isHealthy() ? 'ok' : 'degraded';
    }

    /**
     * `HealthStatus` — what `/health` shows without a token.
     *
     * @return array{status: string, checks: list<array{component: string, status: string}>}
     */
    public function toPublic(): array
    {
        return [
            'status' => $this->status(),
            'checks' => array_map(static fn (ProbeResult $check): array => $check->toPublic(), $this->checks),
        ];
    }

    /**
     * `HealthDetails` — what `/maintenance/health` shows a superuser.
     *
     * @return array<string, mixed>
     */
    public function toDetailed(): array
    {
        return [
            'status' => $this->status(),
            'checked_at' => $this->checkedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'checks' => array_map(static fn (ProbeResult $check): array => $check->toDetailed(), $this->checks),
        ];
    }
}

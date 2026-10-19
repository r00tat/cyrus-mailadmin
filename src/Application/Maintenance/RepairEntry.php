<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One row of `mailadmin_repair_queue`, as the maintenance use cases see it.
 *
 * `retryable` is not stored. It is worked out from the step and the parameters
 * each time the entry is read ({@see RepairCatalogue}), so that widening or
 * narrowing what the application retries applies to entries already in the
 * table.
 */
final readonly class RepairEntry
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public int $id,
        public DateTimeImmutable $recordedAt,
        public string $operation,
        public string $step,
        public string $subject,
        public string $intended,
        public string $reached,
        public string $reason,
        public array $parameters,
        public string $requestId,
        public RepairStatus $status,
        public int $attempts = 0,
        public ?DateTimeImmutable $lastAttemptAt = null,
        public string $lastError = '',
        public ?DateTimeImmutable $resolvedAt = null,
        public string $resolvedBy = '',
        public ?RepairResolution $resolution = null,
        public string $note = '',
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === RepairStatus::Open;
    }

    public function isRetryable(): bool
    {
        return RepairCatalogue::isRetryable($this->step, $this->parameters);
    }

    public function parameter(string $name): string
    {
        return $this->parameters[$name] ?? '';
    }

    /**
     * The domain the entry is about, when it names one — for the audit entry.
     */
    public function domain(): ?string
    {
        $domain = $this->parameter('domain');

        return $domain === '' ? null : $domain;
    }

    /**
     * The API representation (`RepairEntry` in `components/maintenance.yaml`).
     *
     * @return array<string, mixed>
     */
    public function toRepresentation(): array
    {
        return [
            'id' => $this->id,
            'recorded_at' => self::stamp($this->recordedAt),
            'operation' => $this->operation,
            'step' => $this->step,
            'subject' => $this->subject,
            'intended' => $this->intended,
            'reached' => $this->reached,
            'reason' => $this->reason,
            'parameters' => (object) $this->parameters,
            'request_id' => $this->requestId === '' ? null : $this->requestId,
            'status' => $this->status->value,
            'retryable' => $this->isOpen() && $this->isRetryable(),
            'attempts' => $this->attempts,
            'last_attempt_at' => $this->lastAttemptAt === null ? null : self::stamp($this->lastAttemptAt),
            'last_error' => $this->lastError === '' ? null : $this->lastError,
            'resolved_at' => $this->resolvedAt === null ? null : self::stamp($this->resolvedAt),
            'resolved_by' => $this->resolvedBy === '' ? null : $this->resolvedBy,
            'resolution' => $this->resolution?->value,
            'note' => $this->note === '' ? null : $this->note,
        ];
    }

    /**
     * The state the audit trail records before and after a retry or dismissal.
     *
     * @return array<string, mixed>
     */
    public function auditState(): array
    {
        return [
            'operation' => $this->operation,
            'step' => $this->step,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'resolution' => $this->resolution?->value,
            'attempts' => $this->attempts,
        ];
    }

    private static function stamp(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}

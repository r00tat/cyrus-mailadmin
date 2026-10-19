<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * What one probe found. `code` is a stable error code from the contract's
 * `ErrorCode` enumeration when the probe failed, never an exception message.
 */
final readonly class ProbeResult
{
    public function __construct(
        public HealthComponent $component,
        public ProbeStatus $status,
        public ?string $code = null,
        public int $durationMs = 0,
    ) {
    }

    public static function ok(HealthComponent $component, int $durationMs = 0): self
    {
        return new self($component, ProbeStatus::Ok, null, $durationMs);
    }

    public static function error(HealthComponent $component, string $code, int $durationMs = 0): self
    {
        return new self($component, ProbeStatus::Error, $code, $durationMs);
    }

    public static function skipped(HealthComponent $component): self
    {
        return new self($component, ProbeStatus::Skipped);
    }

    /**
     * The thin public form: component and status, nothing else (A6 open
     * point 7, U-121).
     *
     * @return array{component: string, status: string}
     */
    public function toPublic(): array
    {
        return ['component' => $this->component->value, 'status' => $this->status->value];
    }

    /**
     * @return array{component: string, status: string, code: string|null, duration_ms: int}
     */
    public function toDetailed(): array
    {
        return [
            ...$this->toPublic(),
            'code' => $this->code,
            'duration_ms' => $this->durationMs,
        ];
    }
}

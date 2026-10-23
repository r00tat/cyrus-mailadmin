<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps every log line in memory, so a test can check what a use case says at
 * which level — and what it leaves out.
 */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @param list<string> $levels
     *
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function recordsAt(array $levels): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => in_array($record['level'], $levels, true),
        ));
    }
}

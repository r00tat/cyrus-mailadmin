<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

use RuntimeException;

/**
 * Base class for every rule the domain model enforces.
 *
 * Two things every subclass carries:
 *
 *  - a stable `code()` following `<resource>.<state>` (U-7) — this is the
 *    contract, not the message;
 *  - a `context()` map with the offending value, for the log and for the
 *    `errors` member of a problem response.
 *
 * The message is English and written for a log reader. It must never contain a
 * password, a hash or a token — the domain model does not handle any of those,
 * which is why the constructors here are free to quote their input.
 */
abstract class DomainError extends RuntimeException
{
    /**
     * @param array<string, string|int> $context
     */
    protected function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The stable, translatable error code (U-7).
     */
    final public function code(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string|int>
     */
    final public function context(): array
    {
        return $this->context;
    }

    /**
     * A value made safe to put into a message: printable ASCII only, shortened.
     *
     * The domain model never sees a secret, so quoting the offending input is
     * safe and makes a log entry useful. What is not safe is passing arbitrary
     * bytes into a JSON log line — a broken UTF-8 sequence would make the whole
     * entry unserialisable, so anything outside printable ASCII becomes `?`.
     */
    final protected static function excerpt(string $value, int $limit = 80): string
    {
        $printable = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';

        if (strlen($printable) > $limit) {
            return substr($printable, 0, $limit) . '...';
        }

        return $printable;
    }
}

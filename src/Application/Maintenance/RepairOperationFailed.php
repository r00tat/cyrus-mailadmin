<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use RuntimeException;

/**
 * A refusal of the repair-queue use cases, with the status and stable code the
 * handler renders. The message is English for the log and never reaches the
 * client (CLAUDE.md, errors).
 */
final class RepairOperationFailed extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(int $id): self
    {
        return new self(RepairErrorCode::NOT_FOUND, 404, sprintf('There is no repair entry %d.', $id));
    }

    public static function alreadyResolved(int $id): self
    {
        return new self(
            RepairErrorCode::ALREADY_RESOLVED,
            409,
            sprintf('Repair entry %d is already resolved.', $id),
        );
    }

    public static function notRetryable(int $id, string $why): self
    {
        return new self(
            RepairErrorCode::NOT_RETRYABLE,
            409,
            sprintf('Repair entry %d cannot be retried by the application: %s', $id, $why),
        );
    }

    public static function stateChanged(string $why): self
    {
        return new self(RepairErrorCode::STATE_CHANGED, 409, $why);
    }

    public static function retryFailed(int $id, string $errorCode, bool $unavailable): self
    {
        return new self(
            RepairErrorCode::RETRY_FAILED,
            $unavailable ? 503 : 409,
            sprintf('Retrying repair entry %d failed again: %s', $id, $errorCode),
        );
    }

    /**
     * What goes into `last_error` for this refusal — the stable code, never the
     * message.
     */
    public function lastError(): string
    {
        return $this->errorCode;
    }
}

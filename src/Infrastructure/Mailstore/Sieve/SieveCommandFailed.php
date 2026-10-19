<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Sieve;

use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;

/**
 * A ManageSieve command the server answered with `NO` or `BYE`.
 *
 * The counterpart to {@see \Mailadmin\Infrastructure\Mailstore\Imap\CommandFailed},
 * and bound by the same rule: the **response code** is the contract, the English
 * sentence is not. RFC 5804 defines `NONEXISTENT`, `ACTIVE`, `QUOTA/MAXSCRIPTS`
 * and the rest precisely so that a client does not have to read prose — and
 * timsieved's prose differs between versions.
 *
 * This is the protocol-level failure. What it *means* for a script is
 * {@see \Mailadmin\Infrastructure\Mailstore\SieveOperationFailed}'s business:
 * {@see \Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusSieveStore} translates one
 * into the other, so that a caller sees "this script is not there" rather than
 * "DELETESCRIPT answered NO".
 */
final class SieveCommandFailed extends MailstoreError
{
    private function __construct(
        string $errorCode,
        string $message,
        public readonly string $command,
        public readonly string $status,
        public readonly string $serverText,
        public readonly ?string $responseCode,
    ) {
        parent::__construct($errorCode, $message);
    }

    public static function fromResponse(string $command, SieveResponse $response): self
    {
        $text = $response->operatorText();

        return new self(
            ErrorCode::COMMAND_FAILED,
            sprintf(
                '%s answered %s: %s',
                $command,
                $response->status,
                $text === '' ? '(no text)' : $text,
            ),
            $command,
            $response->status,
            $text,
            $response->code,
        );
    }

    /**
     * Exact match or a refinement of the same family — `QUOTA` also answers for
     * `QUOTA/MAXSIZE`.
     */
    public function hasResponseCode(string $code): bool
    {
        if ($this->responseCode === null) {
            return false;
        }

        $wanted = strtoupper($code);

        return strtoupper($this->responseCode) === $wanted
            || str_starts_with(strtoupper($this->responseCode), $wanted . '/');
    }

    public function isRetryable(): bool
    {
        return false;
    }
}

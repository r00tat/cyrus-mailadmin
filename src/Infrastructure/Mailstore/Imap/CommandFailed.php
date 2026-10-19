<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;

/**
 * The server answered `NO` or `BAD`.
 *
 * The distinction matters: `NO` is a refusal of *this* request ("mailbox does
 * not exist"), `BAD` says the request itself was malformed and is therefore our
 * bug. Both carry the server's own text, because for `NO` that text is usually
 * the only description of the problem that exists.
 *
 * The response code in brackets — `[ALREADYEXISTS]`, `[NONEXISTENT]`,
 * `[OVERQUOTA]` — is kept separately. M2 decides what a mailbox that already
 * exists means (its open point 4); reading that out of a translated English
 * sentence would be guesswork, reading it out of the response code is not.
 */
final class CommandFailed extends MailstoreError
{
    private function __construct(
        string $errorCode,
        string $message,
        public readonly string $command,
        public readonly ResponseStatus $status,
        public readonly string $serverText,
        public readonly ?string $responseCode,
    ) {
        parent::__construct($errorCode, $message);
    }

    public static function fromResult(string $command, CommandResult $result): self
    {
        return new self(
            ErrorCode::COMMAND_FAILED,
            sprintf(
                '%s answered %s: %s',
                $command,
                $result->status->value,
                $result->text === '' ? '(no text)' : $result->text,
            ),
            $command,
            $result->status,
            $result->text,
            $result->responseCode,
        );
    }

    /**
     * Does the server's response code match `$code`, case insensitively?
     *
     * The comparison is on the code, never on the text: `NO Mailbox already
     * exists` is not a contract, `[ALREADYEXISTS]` is (RFC 5530).
     */
    public function hasResponseCode(string $code): bool
    {
        return $this->responseCode !== null
            && strcasecmp($this->responseCode, $code) === 0;
    }

    /**
     * A refusal is a refusal. `BAD` in particular means we sent something wrong,
     * and sending it again produces the same answer.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}

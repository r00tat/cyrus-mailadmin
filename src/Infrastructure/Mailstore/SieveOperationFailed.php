<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Throwable;

/**
 * A Sieve operation the server understood and refused.
 *
 * The interesting member is {@see foreignScript()}: it is not a server error at
 * all but a decision of ours. Architecture 9.4 says an active script without the
 * mailadmin marker is shown read-only and never overwritten without an explicit
 * takeover, and a caller needs to be able to tell that case from "the script has
 * a syntax error" — the first asks the user a question, the second is a bug.
 */
final class SieveOperationFailed extends MailstoreError
{
    private function __construct(
        string $errorCode,
        string $message,
        public readonly string $account,
        public readonly string $scriptName,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $message, $previous);
    }

    public static function scriptNotFound(string $account, string $name): self
    {
        return new self(
            ErrorCode::SIEVE_SCRIPT_NOT_FOUND,
            sprintf('There is no script "%s" for "%s".', $name, $account),
            $account,
            $name,
        );
    }

    /**
     * Deleting the active script would leave the account filtering with
     * something that is no longer there. Every ManageSieve server refuses it,
     * and so does the double — see M2's note on being as strict as the server.
     */
    public static function activeScriptCannotBeDeleted(string $account, string $name): self
    {
        return new self(
            ErrorCode::SIEVE_REJECTED,
            sprintf(
                'The script "%s" is active for "%s"; deactivate it before deleting it.',
                $name,
                $account,
            ),
            $account,
            $name,
        );
    }

    /**
     * The active script carries no mailadmin marker (architecture 9.4).
     *
     * The content is deliberately **not** in the message: a Sieve script holds
     * a vacation text, and a vacation text is personal data that has no business
     * in a log.
     */
    public static function foreignScript(string $account, string $name): self
    {
        return new self(
            ErrorCode::SIEVE_SCRIPT_FOREIGN,
            sprintf(
                'The active script "%s" for "%s" was not written by mailadmin; '
                . 'overwriting it needs an explicit takeover.',
                $name,
                $account,
            ),
            $account,
            $name,
        );
    }

    /**
     * A stored but inactive script carries no mailadmin marker and has the
     * name mailadmin would store under — storing would replace it (U-113).
     * The same code as {@see foreignScript()}: the answer is the same
     * question for a person.
     */
    public static function foreignStoredScript(string $account, string $name): self
    {
        return new self(
            ErrorCode::SIEVE_SCRIPT_FOREIGN,
            sprintf(
                'The stored script "%s" for "%s" was not written by mailadmin; '
                . 'replacing it needs an explicit takeover.',
                $name,
                $account,
            ),
            $account,
            $name,
        );
    }

    public static function rejected(
        string $account,
        string $name,
        string $serverText,
        ?Throwable $previous = null,
    ): self {
        return new self(
            ErrorCode::SIEVE_REJECTED,
            sprintf('The server refused the script "%s" for "%s": %s', $name, $account, $serverText),
            $account,
            $name,
            $previous,
        );
    }

    public static function extensionMissing(string $account, string $extension): self
    {
        return new self(
            ErrorCode::SIEVE_EXTENSION_MISSING,
            sprintf(
                'The rule set for "%s" needs the Sieve extension "%s", which the server '
                . 'does not announce.',
                $account,
                $extension,
            ),
            $account,
            '',
        );
    }

    /**
     * None of these change on a second attempt. A foreign script needs a person,
     * a rejected script needs a fix, a missing extension needs a server.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}

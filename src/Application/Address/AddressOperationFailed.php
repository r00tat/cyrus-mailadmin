<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Account\AccountOperationFailed;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Infrastructure\Mailstore\ErrorCode as MailstoreErrorCode;
use RuntimeException;

/**
 * An A3 operation was refused, with the code and status that say why.
 *
 * The shape of A2's `AccountOperationFailed`: the use case decides, the handler
 * renders. The message is for the log; the client gets the code and a fixed
 * text.
 */
final class AddressOperationFailed extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what): self
    {
        return new self(AddressErrorCode::NOT_FOUND, 404, sprintf('There is no %s.', $what));
    }

    /**
     * An account refusal from `AccountLocator`, carried over unchanged — a
     * missing account is the same `404`, a contradictory one the same `409`.
     */
    public static function fromAccount(AccountOperationFailed $failure): self
    {
        return new self($failure->errorCode, $failure->status, $failure->getMessage());
    }

    public static function taken(string $address): self
    {
        return new self(
            AddressErrorCode::TAKEN,
            409,
            sprintf('The address "%s" is already in use.', $address),
        );
    }

    public static function notEditable(string $what, string $why): self
    {
        return new self(
            AddressErrorCode::NOT_EDITABLE,
            409,
            sprintf('%s cannot be changed here: %s', $what, $why),
        );
    }

    public static function reserved(string $address): self
    {
        return new self(
            AddressErrorCode::RESERVED,
            409,
            sprintf(
                'The address "%s" is one a mail domain is expected to have; removing or disabling it '
                . 'needs confirm_reserved.',
                $address,
            ),
        );
    }

    /**
     * Removing or switching off `$address` would leave the domain's enabled
     * catchall with a destination that no longer delivers (U-112).
     */
    public static function neededByCatchall(string $address, string $domain, string $destination): self
    {
        return new self(
            AddressErrorCode::NEEDED_BY_CATCHALL,
            409,
            sprintf(
                'Removing or disabling "%s" would leave the catchall of "%s" without a working destination '
                . '("%s"); change the catchall first.',
                $address,
                $domain,
                $destination,
            ),
        );
    }

    public static function unstorable(string $why): self
    {
        return new self(AddressErrorCode::UNSTORABLE, 422, $why);
    }

    public static function copyRequired(string $storedUsername): self
    {
        return new self(
            AddressErrorCode::FORWARD_COPY_REQUIRED,
            409,
            sprintf(
                'The account "%s" is in the fqun scheme: its address row delivers into the mailbox '
                . 'whatever the forward says, so a forward without a copy cannot be honoured.',
                $storedUsername,
            ),
        );
    }

    public static function foreignScript(string $account): self
    {
        return new self(
            AddressErrorCode::VACATION_FOREIGN_SCRIPT,
            409,
            sprintf('The active Sieve script of "%s" was not written by mailadmin.', $account),
        );
    }

    /**
     * A Sieve failure, translated into what a client can do about it.
     */
    public static function fromSieve(MailstoreFailure $failure, string $account): self
    {
        return match ($failure->errorCode()) {
            MailstoreErrorCode::SIEVE_SCRIPT_FOREIGN => self::foreignScript($account),
            MailstoreErrorCode::SIEVE_EXTENSION_MISSING => new self(
                AddressErrorCode::VACATION_NOT_SUPPORTED,
                409,
                sprintf('The Sieve server of "%s" does not offer the vacation extension.', $account),
            ),
            MailstoreErrorCode::SIEVE_REJECTED => new self(
                AddressErrorCode::VACATION_REJECTED,
                422,
                sprintf('The Sieve server refused the script for "%s".', $account),
            ),
            default => new self(
                AddressErrorCode::VACATION_MAILSTORE_UNAVAILABLE,
                503,
                sprintf('The Sieve server failed for "%s": %s', $account, $failure->errorCode()),
            ),
        };
    }

    public static function preconditionFailed(string $what): self
    {
        return new self(
            AddressErrorCode::PRECONDITION_FAILED,
            412,
            sprintf('%s has changed since the client last read it.', $what),
        );
    }
}

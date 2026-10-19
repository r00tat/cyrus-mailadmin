<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

/**
 * The result of a creation: the account, and the steps after the database
 * write that did not complete although the account works (U-80).
 */
final readonly class AccountCreated
{
    /** The default folders could not be created or subscribed. */
    public const string STEP_DEFAULT_FOLDERS = 'default_folders';

    /**
     * @param list<string> $incompleteSteps
     */
    public function __construct(
        public AccountRecord $account,
        public array $incompleteSteps = [],
    ) {
    }
}

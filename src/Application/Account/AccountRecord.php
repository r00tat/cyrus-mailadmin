<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Model\PasswordFormat;

/**
 * One row of `accountuser`, as A2 reads it.
 *
 * Not {@see \Mailadmin\Domain\Model\Account}, because a row is not always an
 * account the model can describe: a stored user name that contradicts its
 * `domain_name` has no {@see MailboxIdentity}, and U-22 says such a row is shown
 * rather than skipped. `$identity` is therefore nullable, and every write goes
 * through {@see requireIdentity()} — the one place that refuses it.
 *
 * The stored user name is kept verbatim next to the identity: it is the key in
 * the table and in the URL, and nothing re-derives it.
 */
final readonly class AccountRecord
{
    public function __construct(
        public string $storedUsername,
        public DomainName $domain,
        public ?MailboxIdentity $identity,
        public AccountServices $services,
        public PasswordFormat $passwordFormat,
        public string $legacyPrefix,
        public AccountStatus $status = new AccountStatus(),
    ) {
    }

    /**
     * Resolve the stored name against its domain, keeping the row when that
     * fails.
     */
    public static function fromStored(
        string $storedUsername,
        DomainName $domain,
        AccountServices $services,
        PasswordFormat $passwordFormat,
        string $legacyPrefix,
        ?AccountStatus $status = null,
    ): self {
        try {
            $identity = MailboxIdentity::fromStoredUsername($storedUsername, $domain);
        } catch (DomainError) {
            $identity = null;
        }

        return new self(
            $storedUsername,
            $domain,
            $identity,
            $services,
            $passwordFormat,
            $legacyPrefix,
            $status ?? AccountStatus::enabled(),
        );
    }

    /**
     * The scheme of this account's name — recognised, never configured
     * (architecture 3.3). Known even when the identity is not, because the
     * only rule is "contains an `@`".
     */
    public function namingScheme(): NamingScheme
    {
        return $this->identity->scheme ?? NamingScheme::detect($this->storedUsername);
    }

    public function isResolvable(): bool
    {
        return $this->identity !== null;
    }

    /**
     * @throws AccountOperationFailed when the stored name cannot be resolved
     */
    public function requireIdentity(): MailboxIdentity
    {
        return $this->identity
            ?? throw AccountOperationFailed::identityMismatch($this->storedUsername, $this->domain->toString());
    }

    public function withServices(AccountServices $services): self
    {
        return new self(
            $this->storedUsername,
            $this->domain,
            $this->identity,
            $services,
            $this->passwordFormat,
            $this->legacyPrefix,
            $this->status,
        );
    }

    public function withPasswordFormat(PasswordFormat $format): self
    {
        return new self(
            $this->storedUsername,
            $this->domain,
            $this->identity,
            $this->services,
            $format,
            $this->legacyPrefix,
            $this->status,
        );
    }

    public function withStatus(AccountStatus $status): self
    {
        return new self(
            $this->storedUsername,
            $this->domain,
            $this->identity,
            $this->services,
            $this->passwordFormat,
            $this->legacyPrefix,
            $status,
        );
    }
}

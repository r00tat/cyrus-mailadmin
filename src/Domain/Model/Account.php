<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

/**
 * A mail account — one row in `accountuser`, seen through `MailboxIdentity`.
 *
 * **The identity is the account's identity** (U-18): `id()` returns the stored
 * Cyrus user name, and that is the `{accountId}` of every account path in the
 * API. There is no second, numeric key — the legacy table has none, and adding
 * one would create a second notion of „which account is this" for something
 * that already has several spellings.
 *
 * **The naming scheme is recognised, never derived from configuration** (E-14).
 * It arrives inside the identity, which was built from the stored user name and
 * `accountuser.domain_name`. When it differs from what the domain configures
 * for new accounts, that is not an error to repair but a fact to show
 * ({@see divergesFromDomainScheme()}, U-22).
 *
 * The quota is deliberately absent: it lives in Cyrus, not in the database, and
 * an account listing must not pay an IMAP round trip per row (architecture
 * 7.2).
 */
final readonly class Account
{
    public function __construct(
        public MailboxIdentity $identity,
        public AccountServices $services,
        public PasswordFormat $passwordFormat = PasswordFormat::Unknown,
        public string $legacyPrefix = '',
    ) {
    }

    /**
     * The account's identifier: the stored Cyrus user name (U-18).
     *
     * Handed to the HTTP layer as-is; URL encoding happens there, not here.
     */
    public function id(): string
    {
        return $this->identity->cyrusUsername();
    }

    public function address(): EmailAddress
    {
        return $this->identity->address();
    }

    public function domain(): DomainName
    {
        return $this->identity->domain;
    }

    public function localPart(): LocalPart
    {
        return $this->identity->localPart;
    }

    /**
     * The scheme this account's mailbox actually lives under.
     */
    public function namingScheme(): NamingScheme
    {
        return $this->identity->scheme;
    }

    /**
     * Does the account's scheme differ from what its domain gives new accounts?
     *
     * Shown per account (U-22), never corrected: moving an account between
     * schemes is `renamemailbox` plus new credentials for the user
     * (architecture 8.1).
     */
    public function divergesFromDomainScheme(NamingScheme $domainScheme): bool
    {
        return $this->identity->scheme !== $domainScheme;
    }

    /**
     * Does the stored password use one of the current target formats (E-13)?
     */
    public function hasCurrentPasswordFormat(): bool
    {
        return $this->passwordFormat->isCurrent();
    }

    public function withServices(AccountServices $services): self
    {
        return new self($this->identity, $services, $this->passwordFormat, $this->legacyPrefix);
    }

    public function withPasswordFormat(PasswordFormat $format): self
    {
        return new self($this->identity, $this->services, $format, $this->legacyPrefix);
    }

    /**
     * The account under a different identity — the target of a rename, a domain
     * rename or a scheme change. Produces the target state, performs nothing.
     */
    public function withIdentity(MailboxIdentity $identity): self
    {
        return new self($identity, $this->services, $this->passwordFormat, $this->legacyPrefix);
    }

    public function equals(self $other): bool
    {
        return $this->identity->equals($other->identity);
    }
}

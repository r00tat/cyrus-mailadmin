<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Mailstore\Quota;

/**
 * A mail domain with its allowances and defaults — the row in `domain`, plus
 * what `mailadmin_domain_setting` adds (A1 owns both).
 *
 * Immutable, like everything else here: a change produces a new instance, the
 * use case decides whether it reaches the database. Two of the legacy columns
 * are carried without being interpreted:
 *
 *  - **`prefix`** is `NOT NULL UNIQUE` and was misused as a surrogate key for
 *    the domain. It cannot be removed (E-1), so it is carried verbatim. What a
 *    new domain gets written into it is A1's open point 1.
 *  - **`transport`** is `cyrus` on every row and the legacy code aborted on
 *    anything else. Carried, never read.
 */
final readonly class Domain
{
    /** The only value the column ever carries. */
    public const string DEFAULT_TRANSPORT = 'cyrus';

    public function __construct(
        public DomainName $name,
        public AccountLimit $maxAccounts,
        public Quota $defaultAccountQuota,
        public Quota $domainQuota,
        public DefaultFolders $defaultFolders,
        public ?NamingScheme $namingScheme = null,
        public string $legacyPrefix = '',
        public string $transport = self::DEFAULT_TRANSPORT,
        public bool $freeNames = true,
        public bool $freeAddress = true,
    ) {
    }

    /**
     * What a *new* account in this domain gets (architecture 3.3, three steps).
     *
     * `$globalDefault` is `MAILADMIN_NAMING_SCHEME`. Note what this method is
     * **not**: the scheme of an account that already exists. That one is
     * recognised from the stored user name and may well differ — U-22 asks for
     * exactly that difference to be visible.
     */
    public function namingSchemeForNewAccounts(?NamingScheme $globalDefault = null): NamingScheme
    {
        return NamingScheme::resolve($this->namingScheme, $globalDefault);
    }

    /**
     * The identity a new account with this local part would get.
     */
    public function identityFor(
        LocalPart $localPart,
        ?NamingScheme $globalDefault = null,
    ): MailboxIdentity {
        return MailboxIdentity::create(
            $localPart,
            $this->name,
            $this->namingSchemeForNewAccounts($globalDefault),
        );
    }

    /**
     * Is there room for one more account? A1 decides where this is asked and
     * how a concurrent creation is handled (its open point 5) — the rule itself
     * lives here.
     */
    public function permitsAnotherAccount(int $currentAccountCount): bool
    {
        return $this->maxAccounts->permitsAnotherAccount($currentAccountCount);
    }

    /**
     * `fqun` needs the domain in the Cyrus `loginrealms`, which Salt writes and
     * we cannot read (E-9). The interface warns when this is true.
     */
    public function needsLoginRealmHint(?NamingScheme $globalDefault = null): bool
    {
        return $this->namingSchemeForNewAccounts($globalDefault)->requiresLoginRealm();
    }

    public function withNamingScheme(?NamingScheme $scheme): self
    {
        return new self(
            $this->name,
            $this->maxAccounts,
            $this->defaultAccountQuota,
            $this->domainQuota,
            $this->defaultFolders,
            $scheme,
            $this->legacyPrefix,
            $this->transport,
            $this->freeNames,
            $this->freeAddress,
        );
    }

    /**
     * The same domain under a different name — the target of a rename.
     *
     * A rename is `renamemailbox` across every account of the domain and only
     * then the database (architecture 8.1). This method produces the target
     * state; it performs nothing.
     */
    public function renamedTo(DomainName $name): self
    {
        return new self(
            $name,
            $this->maxAccounts,
            $this->defaultAccountQuota,
            $this->domainQuota,
            $this->defaultFolders,
            $this->namingScheme,
            $this->legacyPrefix,
            $this->transport,
            $this->freeNames,
            $this->freeAddress,
        );
    }

    public function withAllowances(
        AccountLimit $maxAccounts,
        Quota $defaultAccountQuota,
        Quota $domainQuota,
    ): self {
        return new self(
            $this->name,
            $maxAccounts,
            $defaultAccountQuota,
            $domainQuota,
            $this->defaultFolders,
            $this->namingScheme,
            $this->legacyPrefix,
            $this->transport,
            $this->freeNames,
            $this->freeAddress,
        );
    }

    public function withDefaultFolders(DefaultFolders $folders): self
    {
        return new self(
            $this->name,
            $this->maxAccounts,
            $this->defaultAccountQuota,
            $this->domainQuota,
            $folders,
            $this->namingScheme,
            $this->legacyPrefix,
            $this->transport,
            $this->freeNames,
            $this->freeAddress,
        );
    }

    /**
     * The domain's catchall alias as `virtual.alias` spells it (architecture
     * 3.4).
     */
    public function catchAllAlias(): string
    {
        return '@' . $this->name->toString();
    }

    public function equals(self $other): bool
    {
        return $this->name->equals($other->name);
    }
}

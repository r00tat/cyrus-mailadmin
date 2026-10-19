<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AccountLimit;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;

/**
 * What `POST /domains` asks for: a name, and whatever else the body mentioned.
 *
 * ## The defaults are webcyradm's, not this application's
 *
 * A domain created with nothing but a name is the domain webcyradm's
 * `create_mysql.sql` would have created: twenty accounts, a 20000 KiB account
 * quota, no domain quota, `transport = cyrus`, `freenames` and `freeaddress`
 * off, no default folders. Choosing different numbers here would mean two
 * installations disagreeing about what "a domain" is depending on which
 * interface made it, which is exactly the kind of divergence the parallel
 * operation with webcyradm cannot afford (E-1).
 *
 * Note that {@see Domain}'s own constructor defaults for the two flags are the
 * opposite. That is not an inconsistency to tidy up: the value object describes
 * what a *domain object* is when nobody says otherwise, this describes what the
 * *column default* is, and A1 is the package that has to honour the column.
 *
 * ## The name goes through the strict door
 *
 * {@see DomainName::fromString()}, not `fromStoredValue()`. The tolerant door
 * exists for rows that are already in the database (U-22); a name somebody just
 * typed has no such claim, and letting one in would create a domain that this
 * application could never have offered to create.
 */
final readonly class DomainDraft
{
    /** `domain.maxaccounts` — webcyradm's column default. */
    public const int DEFAULT_MAX_ACCOUNTS = 20;

    /** `domain.quota`, in KiB — webcyradm's column default. */
    public const int DEFAULT_ACCOUNT_QUOTA_KIB = 20000;

    private function __construct(
        public DomainName $name,
        public DomainAttributes $attributes,
    ) {
    }

    /**
     * @param array<string, mixed> $body the validated request body
     *
     * @throws DomainError when the name is not one this application would issue
     */
    public static function fromRequest(array $body): self
    {
        /** @var mixed $name */
        $name = $body['name'] ?? '';

        return new self(
            DomainName::fromString(is_string($name) ? $name : ''),
            DomainAttributes::fromRequest($body),
        );
    }

    /**
     * The row to insert, once the caller has found a free legacy prefix.
     *
     * @throws DomainError when one of the requested values is impossible
     */
    public function toDomain(string $legacyPrefix): Domain
    {
        return $this->attributes->applyTo(new Domain(
            name: $this->name,
            maxAccounts: AccountLimit::of(self::DEFAULT_MAX_ACCOUNTS),
            defaultAccountQuota: Quota::ofKibibytes(self::DEFAULT_ACCOUNT_QUOTA_KIB),
            domainQuota: Quota::unlimited(),
            defaultFolders: DefaultFolders::none(),
            namingScheme: null,
            legacyPrefix: $legacyPrefix,
            transport: Domain::DEFAULT_TRANSPORT,
            freeNames: false,
            freeAddress: false,
        ));
    }
}

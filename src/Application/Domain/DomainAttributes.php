<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AccountLimit;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\NamingScheme;

/**
 * The fields of a domain a request may set, and — the part that matters —
 * **which of them it actually mentioned**.
 *
 * A `PATCH` has to tell "leave `max_accounts` alone" from "set it to unlimited",
 * and both arrive as an absent-or-null integer. So this class keeps the raw map
 * of the keys the body carried rather than a set of nullable properties: absence
 * is a key that is not there, `null` is a key whose value is `null`, and the two
 * cannot be confused because they are not the same shape.
 *
 * The contract already rejects an unknown key (`additionalProperties: false`) and
 * a wrong type. The narrowing here is not a second validation pass — it is what
 * makes the class total: a value that is not the shape it should be is treated as
 * absent, so a body that somehow got past the validator changes nothing instead
 * of changing something arbitrary.
 *
 * {@see applyTo()} is where the value objects are built, and therefore where a
 * bad value is rejected: an impossible folder name or a negative quota raises a
 * {@see DomainError}, whose stable code the handler turns into `422`.
 */
final readonly class DomainAttributes
{
    public const string MAX_ACCOUNTS = 'max_accounts';
    public const string ACCOUNT_QUOTA = 'default_account_quota_kib';
    public const string DOMAIN_QUOTA = 'domain_quota_kib';
    public const string FOLDERS = 'default_folders';
    public const string NAMING_SCHEME = 'naming_scheme';
    public const string FREE_NAMES = 'free_names';
    public const string FREE_ADDRESS = 'free_address';

    /** @var list<string> */
    private const array FIELDS = [
        self::MAX_ACCOUNTS,
        self::ACCOUNT_QUOTA,
        self::DOMAIN_QUOTA,
        self::FOLDERS,
        self::NAMING_SCHEME,
        self::FREE_NAMES,
        self::FREE_ADDRESS,
    ];

    /**
     * @param array<string, mixed> $fields only keys from {@see FIELDS}, only the
     *                                     ones the request carried
     */
    private function __construct(private array $fields)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, mixed> $body the validated request body
     */
    public static function fromRequest(array $body): self
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                /** @var mixed $value */
                $value = $body[$field];
                $fields[$field] = $value;
            }
        }

        return new self($fields);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function mentions(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /**
     * The naming scheme this request asks for, and whether it asks at all.
     *
     * `[false, null]` means the body said nothing; `[true, null]` means it said
     * `inherited`, which is a value and not an omission.
     *
     * @return array{bool, NamingScheme|null}
     */
    public function namingScheme(): array
    {
        if (!$this->mentions(self::NAMING_SCHEME)) {
            return [false, null];
        }

        /** @var mixed $value */
        $value = $this->fields[self::NAMING_SCHEME];

        if (!is_string($value) || $value === DomainView::INHERITED) {
            return [true, null];
        }

        return [true, NamingScheme::tryFrom($value)];
    }

    /**
     * `$domain` with every mentioned field replaced, everything else carried.
     *
     * `name`, `legacy_prefix` and `transport` are never touched here: the first
     * is a rename (architecture 8.1), the second is assigned once at creation
     * (U-46) and the third is carried and never interpreted (U-47).
     *
     * @throws DomainError when a value cannot become the value object it has to
     */
    public function applyTo(Domain $domain): Domain
    {
        [$setsScheme, $scheme] = $this->namingScheme();

        return new Domain(
            name: $domain->name,
            maxAccounts: $this->mentions(self::MAX_ACCOUNTS)
                ? self::limit($this->fields[self::MAX_ACCOUNTS])
                : $domain->maxAccounts,
            defaultAccountQuota: $this->mentions(self::ACCOUNT_QUOTA)
                ? self::quota($this->fields[self::ACCOUNT_QUOTA])
                : $domain->defaultAccountQuota,
            domainQuota: $this->mentions(self::DOMAIN_QUOTA)
                ? self::quota($this->fields[self::DOMAIN_QUOTA])
                : $domain->domainQuota,
            defaultFolders: $this->mentions(self::FOLDERS)
                ? self::folders($this->fields[self::FOLDERS])
                : $domain->defaultFolders,
            namingScheme: $setsScheme ? $scheme : $domain->namingScheme,
            legacyPrefix: $domain->legacyPrefix,
            transport: $domain->transport,
            freeNames: $this->mentions(self::FREE_NAMES)
                ? self::flag($this->fields[self::FREE_NAMES], $domain->freeNames)
                : $domain->freeNames,
            freeAddress: $this->mentions(self::FREE_ADDRESS)
                ? self::flag($this->fields[self::FREE_ADDRESS], $domain->freeAddress)
                : $domain->freeAddress,
        );
    }

    /**
     * `null` is "no limit" — the state, not the number zero. A limit of `0` is a
     * domain in which no account may be created, and the two are kept apart by
     * the value object rather than by a magic number (U-48).
     *
     * @throws DomainError
     */
    private static function limit(mixed $value): AccountLimit
    {
        if (!is_int($value)) {
            return AccountLimit::unlimited();
        }

        return AccountLimit::of($value);
    }

    /**
     * @throws DomainError
     */
    private static function quota(mixed $value): Quota
    {
        if (!is_int($value)) {
            return Quota::unlimited();
        }

        return Quota::ofKibibytes($value);
    }

    /**
     * @throws DomainError
     */
    private static function folders(mixed $value): DefaultFolders
    {
        if (!is_array($value)) {
            return DefaultFolders::none();
        }

        $names = [];

        /** @var mixed $name */
        foreach ($value as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return DefaultFolders::of(...$names);
    }

    private static function flag(mixed $value, bool $fallback): bool
    {
        return is_bool($value) ? $value : $fallback;
    }
}

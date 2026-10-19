<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Model\NamingScheme;

/**
 * A {@see DomainSummary} in the shape the contract describes, and the `ETag`
 * over exactly that shape (U-19).
 *
 * ## Why the ETag is computed here and not in the handler
 *
 * U-19 makes the validator a hash of **the representation**, not of the database
 * row: a column webcyradm writes in parallel must not invalidate a client's
 * copy. That only holds if the bytes hashed are the bytes rendered, so the two
 * are produced by one class, from one array, in one order. A handler that
 * serialised the array a second time to hash it would be one JSON flag away from
 * an `ETag` that never matches.
 *
 * The hash is over a canonical JSON encoding with the keys in declaration order
 * — the array is built here, so the order is fixed by this file rather than by
 * whatever the database returned.
 *
 * ## `inherited` rather than `null`
 *
 * `naming_scheme` reports what the domain itself says, and "says nothing" is a
 * value of its own. `effective_naming_scheme` reports what that currently
 * resolves to. Collapsing the two into one nullable field would make the global
 * default invisible, and a client would have to reimplement architecture 3.3's
 * resolution to render a form.
 */
final readonly class DomainView
{
    /** What `naming_scheme` carries when the domain says nothing. */
    public const string INHERITED = 'inherited';

    private function __construct()
    {
    }

    /**
     * The full representation — `GET /domains/{domain}` and the body of a
     * create or update.
     *
     * @return array<string, mixed>
     */
    public static function full(DomainSummary $summary, DomainDefaults $defaults): array
    {
        $domain = $summary->domain;

        return [
            ...self::listItem($summary, $defaults),
            'default_folders' => $domain->defaultFolders->names(),
            'legacy_prefix' => $domain->legacyPrefix,
            'transport' => $domain->transport,
            'free_names' => $domain->freeNames,
            'free_address' => $domain->freeAddress,
        ];
    }

    /**
     * The collection shape: what a table shows, and nothing that costs a round
     * trip to Cyrus.
     *
     * @return array<string, mixed>
     */
    public static function listItem(DomainSummary $summary, DomainDefaults $defaults): array
    {
        $domain = $summary->domain;

        return [
            'name' => $domain->name->toString(),
            'account_count' => $summary->accountCount,
            'max_accounts' => $domain->maxAccounts->maximum(),
            'default_account_quota_kib' => $domain->defaultAccountQuota->limitKibibytes(),
            'domain_quota_kib' => $domain->domainQuota->limitKibibytes(),
            'naming_scheme' => $domain->namingScheme?->value ?? self::INHERITED,
            'effective_naming_scheme' => $defaults->resolve($domain->namingScheme)->value,
            'conventional_name' => $domain->name->isConventional(),
        ];
    }

    /**
     * The steps mailadmin cannot take and the operator has to (E-4, E-9).
     *
     * Stable keys, never sentences: the API is language neutral and the frontend
     * translates (E-16). `cyrus_loginrealm` appears only for `fqun`, which needs
     * the domain in a Cyrus configuration file Salt owns and we can neither read
     * nor write — so the interface says so and does not pretend to have checked.
     *
     * @return list<string>
     */
    public static function nextSteps(DomainSummary $summary, DomainDefaults $defaults): array
    {
        $steps = ['salt_pillar', 'salt_apply'];

        if ($defaults->resolve($summary->domain->namingScheme) === NamingScheme::Fqun) {
            $steps[] = 'cyrus_loginrealm';
        }

        return [...$steps, 'dns_mx', 'dns_spf', 'dns_dkim', 'dns_dmarc'];
    }

    /**
     * The weak validator for a representation produced by {@see full()}.
     *
     * Weak (`W/`) because it identifies the representation, not a byte sequence:
     * two responses with the same fields are the same version of the domain even
     * if one of them was pretty-printed.
     *
     * @param array<string, mixed> $representation
     */
    public static function etag(array $representation): string
    {
        $canonical = json_encode(
            $representation,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return 'W/"' . hash('sha256', $canonical) . '"';
    }

    /**
     * Does an `If-Match` value name this representation?
     *
     * A header may carry a list, and a client that echoes back what it was given
     * sends it verbatim — including the `W/` prefix and the quotes. `*` matches
     * anything that exists, which is what RFC 9110 says and is a legitimate way
     * to say "as long as it is still there".
     *
     * The comparison is weak, because the validator is: two representations with
     * the same fields are the same version.
     */
    public static function matchesEtag(string $ifMatch, string $etag): bool
    {
        $header = trim($ifMatch);

        if ($header === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            if (self::normaliseEtag($candidate) === self::normaliseEtag($etag)) {
                return true;
            }
        }

        return false;
    }

    private static function normaliseEtag(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, 'W/')) {
            $trimmed = trim(substr($trimmed, 2));
        }

        return trim($trimmed, '"');
    }
}

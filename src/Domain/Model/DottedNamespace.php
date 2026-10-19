<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Exception\InvalidAddress;

/**
 * Where the dotted naming scheme makes two accounts share one Cyrus user name.
 *
 * In the dotted scheme `info.kunde@example.de` and `info@kunde.example.de` both
 * become `info.kunde.example.de` (architecture 3.3). The collision always runs
 * between a domain and one of its subdomains, and it can arise from either side:
 *
 *  - an **account** whose local part has dots reaches into the namespace of
 *    every subdomain its tails name — {@see subdomainsReachedBy()}, checked by
 *    account creation (U-86) and by a rename for the accounts it moves (U-96);
 *  - a **domain** that is created or renamed into existence lands in the
 *    namespace of every parent domain whose dotted accounts already end in
 *    `.<its name>` — the parents are {@see parentsOf()}, and the accounts are
 *    looked up by the store (U-96).
 *
 * Pure functions over the value objects; what exists is the store's business.
 */
final class DottedNamespace
{
    private function __construct()
    {
    }

    /**
     * The subdomains whose accounts would share user names with this one.
     *
     * `info.kunde@example.de` reaches `kunde.example.de`; `a.b.c@example.de`
     * reaches `b.c.example.de` and `c.example.de`. Nothing in the `fqun` scheme,
     * where the `@` keeps local part and domain apart, and nothing for a local
     * part without a dot.
     *
     * A tail that is not a usable domain name is skipped: no domain can exist
     * under it.
     *
     * @return list<DomainName>
     */
    public static function subdomainsReachedBy(MailboxIdentity $identity): array
    {
        if ($identity->scheme !== NamingScheme::Dotted || !$identity->localPart->containsDot()) {
            return [];
        }

        $labels = explode('.', $identity->localPart->toString());
        $reached = [];

        for ($i = 1, $n = count($labels); $i < $n; ++$i) {
            $candidate = self::tryName(
                implode('.', array_slice($labels, $i)) . '.' . $identity->domain->toString(),
            );

            if ($candidate !== null) {
                $reached[] = $candidate;
            }
        }

        return $reached;
    }

    /**
     * The domain whose name is this account's Cyrus user name, if the name can
     * be one: `mail@example.de` in the `dotted` scheme is `mail.example.de`.
     *
     * Such a domain's aliases carry its name as their placeholder sender
     * (U-106); an account under that name would gain the right to send as all
     * of them through `validsenders` (U-110). Nothing in the `fqun` scheme,
     * where the `@` keeps the user name from being a domain name.
     */
    public static function domainNamedLike(MailboxIdentity $identity): ?DomainName
    {
        if ($identity->scheme !== NamingScheme::Dotted) {
            return null;
        }

        return self::tryName($identity->cyrusUsername());
    }

    /**
     * Every proper suffix of the name at a label boundary, longest first:
     * `a.b.example.de` gives `b.example.de`, `example.de`, `de`.
     *
     * @return list<DomainName>
     */
    public static function parentsOf(DomainName $name): array
    {
        $labels = $name->labels();
        $parents = [];

        for ($i = 1, $n = count($labels); $i < $n; ++$i) {
            $candidate = self::tryName(implode('.', array_slice($labels, $i)));

            if ($candidate !== null) {
                $parents[] = $candidate;
            }
        }

        return $parents;
    }

    private static function tryName(string $value): ?DomainName
    {
        try {
            return DomainName::fromStoredValue($value);
        } catch (InvalidAddress) {
            return null;
        }
    }
}

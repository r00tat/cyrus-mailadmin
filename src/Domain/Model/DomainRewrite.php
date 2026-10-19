<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\Virtual\DestinationList;

/**
 * Every string a domain rename has to change, and — more importantly — every
 * string it must leave alone (A1, architecture 8.1).
 *
 * A rename touches four kinds of value: the Cyrus user name of each account,
 * `virtual.alias`, `virtual.username` and `virtual.dest`. The first three are
 * handled elsewhere because they need the account table. This class owns the
 * two that are pure string work, and it exists so that the rule can be unit
 * tested rather than read out of an `UPDATE` statement.
 *
 * ## What is unambiguous, and what is not
 *
 * An **alias** carries an `@`, so the domain part is exactly what follows the
 * last one. `@example.test` (a catchall) and `info@example.test` (an address)
 * are both rewritten by replacing that suffix, and nothing else can look like
 * it.
 *
 * A **destination** may be an address or a Cyrus user name, and a `dotted` user
 * name has no separator at all: `info.sub.example.test` is `info` in
 * `sub.example.test` — or `info.sub` in `example.test`. There is no way to tell
 * from the string (architecture 3.3). So a destination is rewritten only when it
 * is
 *
 *  - an address whose domain **equals** the old one, or
 *  - a user name the caller has already looked up in `accountuser` and can
 *    therefore name exactly.
 *
 * Anything else is left untouched. That is deliberately conservative: a
 * destination this class fails to rewrite is a forward that still points at the
 * old name and shows up in A6's reconciliation, whereas one it rewrites wrongly
 * is mail delivered to the wrong mailbox.
 */
final readonly class DomainRewrite
{
    public function __construct(
        public DomainName $from,
        public DomainName $to,
    ) {
    }

    public function isNoop(): bool
    {
        return $this->from->toString() === $this->to->toString();
    }

    /**
     * `@old.example` — the suffix of every alias and catchall of the domain.
     */
    public function oldAliasSuffix(): string
    {
        return '@' . $this->from->toString();
    }

    public function newAliasSuffix(): string
    {
        return '@' . $this->to->toString();
    }

    /**
     * A `virtual.alias` rewritten, or `null` when the value does not belong to
     * the old domain and must stay as it is.
     *
     * Case insensitive on the domain part, because `virtual.alias` is not a
     * binary column and a mixed-case row is still the same address.
     */
    public function alias(string $alias): ?string
    {
        $value = trim($alias);
        $suffix = $this->oldAliasSuffix();

        if (strlen($value) < strlen($suffix)) {
            return null;
        }

        if (strcasecmp(substr($value, -strlen($suffix)), $suffix) !== 0) {
            return null;
        }

        return substr($value, 0, strlen($value) - strlen($suffix)) . $this->newAliasSuffix();
    }

    /**
     * One destination rewritten, or `null` when it must stay as it is.
     *
     * @param array<string, string> $renamedAccounts stored user name (any case)
     *                                               => the name it now has
     */
    public function destination(string $destination, array $renamedAccounts = []): ?string
    {
        $value = trim($destination);

        if ($value === '') {
            return null;
        }

        $renamed = self::lookup($renamedAccounts, $value);

        if ($renamed !== null) {
            return $renamed;
        }

        if (!EmailAddress::looksLikeAddress($value)) {
            // A `dotted` user name the caller did not name. Not ours to guess at.
            return null;
        }

        try {
            $address = EmailAddress::parseStored($value);
        } catch (DomainError) {
            return null;
        }

        if (!$address->domain->equals($this->from)) {
            return null;
        }

        return $address->localPart->toString() . $this->newAliasSuffix();
    }

    /**
     * A whole `virtual.dest` value rewritten, or `null` when nothing in it
     * changes.
     *
     * The list keeps its order and its length; only the entries that name the
     * old domain move. Returning `null` rather than the unchanged string is what
     * lets a caller skip the `UPDATE` — a rename over a large `virtual` table
     * should write the rows it has to and no others.
     *
     * @param array<string, string> $renamedAccounts
     */
    public function destinations(string $dest, array $renamedAccounts = []): ?string
    {
        $list = DestinationList::fromLegacyString($dest);

        if ($list->isEmpty()) {
            return null;
        }

        $changed = false;
        $rewritten = [];

        foreach ($list->all() as $destination) {
            $new = $this->destination($destination, $renamedAccounts);

            if ($new === null) {
                $rewritten[] = $destination;

                continue;
            }

            $changed = true;
            $rewritten[] = $new;
        }

        return $changed ? implode(DestinationList::SEPARATOR, $rewritten) : null;
    }

    /**
     * @param array<string, string> $renamedAccounts
     */
    private static function lookup(array $renamedAccounts, string $value): ?string
    {
        $wanted = strtolower($value);

        foreach ($renamedAccounts as $old => $new) {
            if (strtolower((string) $old) === $wanted) {
                return $new;
            }
        }

        return null;
    }
}

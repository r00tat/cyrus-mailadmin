<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\Virtual\DestinationList;

/**
 * What may stand on the right-hand side of a row A3 writes (A3, open point 1;
 * U-104).
 *
 * A destination is one of two things:
 *
 *  - **the stored user name of an account** — local delivery. Matched without
 *    regard to case and written in the spelling `accountuser` has, because
 *    that is the spelling Cyrus knows the mailbox by. In the `dotted` scheme it
 *    has no `@`; an unknown name without `@` is refused, because Postfix would
 *    append `$myorigin` and send the mail somewhere nobody meant;
 *  - **a mail address** — anything else, local or not. Not checked against the
 *    actor's scope (U-44, A3 open point 6).
 *
 * The list is written comma-separated without spaces, the spelling every
 * legacy row has and Postfix splits on (`DestinationList::toLegacyString()`).
 * At most {@see MAX} entries; a duplicate is refused rather than silently
 * dropped, and so is a destination that would send the mail back to where it
 * came from.
 *
 * **Nothing on the list transport domain** ({@see ListDelivery}). Only a
 * list's own row, written through `/lists`, delivers there. Postfix checks
 * nothing after virtual rewriting, so an alias, forward or catchall naming
 * `<key>@<transport domain>` would feed any list — another domain's included —
 * and strand the row as something the address management cannot remove.
 */
final readonly class DestinationRules
{
    /** The contract's `maxItems` — the limit of open point 1. */
    public const int MAX = 50;

    public function __construct(
        private AddressStore $store,
        private ListDelivery $delivery,
    ) {
    }

    /**
     * @param array<array-key, mixed> $input     the `destinations` array of a request
     * @param list<string>            $forbidden values that would make a loop —
     *                                           the address itself, an account's
     *                                           own user name — compared
     *                                           without regard to case
     *
     * @throws InvalidAddress
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function parse(array $input, array $forbidden = []): DestinationList
    {
        if (count($input) > self::MAX) {
            throw InvalidAddress::unusableDestination(sprintf('%d destinations', count($input)));
        }

        $values = [];

        foreach ($input as $value) {
            if (!is_string($value)) {
                throw InvalidAddress::unusableDestination(get_debug_type($value));
            }

            $values[] = trim($value);
        }

        // Shape first: no empty value, no comma, no whitespace.
        $list = DestinationList::of(...$values);

        foreach ($list->all() as $destination) {
            if ($this->isOnTransport($destination)) {
                throw InvalidAddress::unusableDestination($destination);
            }
        }

        $accounts = $this->accounts($list->all());
        $seen = [];
        $loops = array_map('strtolower', $forbidden);
        $clean = [];

        foreach ($list->all() as $destination) {
            $key = strtolower($destination);

            if (isset($seen[$key]) || in_array($key, $loops, true)) {
                throw InvalidAddress::unusableDestination($destination);
            }

            $seen[$key] = true;
            $clean[] = $accounts[$key] ?? $this->external($destination);
        }

        return DestinationList::of(...$clean);
    }

    /**
     * lower-cased destination => stored user name, for the destinations that are
     * accounts.
     *
     * @param list<string> $destinations
     *
     * @return array<string, string>
     *
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function accounts(array $destinations): array
    {
        $found = [];

        foreach (array_keys($this->store->accountDomains($destinations)) as $username) {
            $found[strtolower((string) $username)] = (string) $username;
        }

        return $found;
    }

    private function isOnTransport(string $destination): bool
    {
        $at = strrpos($destination, '@');

        return $at !== false
            && strcasecmp(substr($destination, $at + 1), $this->delivery->transportDomain->toString()) === 0;
    }

    /**
     * @throws InvalidAddress
     */
    private function external(string $destination): string
    {
        if (!EmailAddress::looksLikeAddress($destination)) {
            throw InvalidAddress::unusableDestination($destination);
        }

        try {
            return EmailAddress::parseStored($destination)->toString();
        } catch (DomainError) {
            throw InvalidAddress::unusableDestination($destination);
        }
    }
}

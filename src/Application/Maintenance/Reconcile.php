<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\DottedNamespace;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Model\Virtual\MailingListEntry;
use Mailadmin\Domain\Model\Virtual\MailingListRecognition;
use Mailadmin\Domain\Model\Virtual\UnknownVirtualEntry;
use Mailadmin\Domain\Model\Virtual\VirtualEntryClassifier;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Mailadmin\Runtime\Clock;

/**
 * `GET /maintenance/reconciliation`: what the database and Cyrus disagree about
 * (A6, architecture 8.2).
 *
 * **Report only** (E-18). Nothing here writes, renames or deletes; the answer
 * is a list for a human.
 *
 * ## How the two sides are read
 *
 * The database side is read whole, once ({@see MaintenanceInventory}). The
 * Cyrus side is one `LIST "" "user/%"` as the global administrator. Measured
 * against F5 (U-116), that single listing returns the user roots of **both**
 * namespaces — `user/anna.example.test` beside `user/hans@beispiel.invalid` —
 * so there is no second query whose absence could hide a shadow mailbox. `%`
 * stops at the first level, so folders are not listed, and mailboxes Cyrus
 * keeps under `DELETED/` after a delayed delete are not matched by the pattern
 * and are filtered by the adapter as well (M2, U-38c).
 *
 * An account's mailbox is `user/` + its stored user name in either scheme, so
 * comparing the two sides needs no decomposition of any name. Comparison ignores
 * case, as Cyrus and the adapter do.
 *
 * ## Names are reported, never guessed
 *
 * A destination without `@` is a dotted Cyrus user name, and from the string
 * alone nobody can tell where its local part ends (CLAUDE.md). One that no
 * account has — the case A1's rename leaves behind when it could not look a
 * name up — is reported exactly as written, with no domain filled in (U-119).
 */
final readonly class Reconcile
{
    public const string PATTERN = 'user/%';

    private const string USER_PREFIX = 'user/';

    public function __construct(
        private MaintenanceInventory $inventory,
        private Mailstore $mailstore,
        private Clock $clock,
        private ListDelivery $delivery,
    ) {
    }

    /**
     * @throws MailstoreFailure when Cyrus cannot be listed — no half report
     * @throws PersistenceError
     */
    public function handle(ActorContext $actor): ReconciliationReport
    {
        $accounts = $this->inventory->accounts();
        $domains = $this->inventory->domains();
        $rows = $this->inventory->virtualRows();
        $lists = $this->inventory->listAddresses();
        $mailboxes = $this->mailboxes();

        $findings = [];

        /** @var array<string, true> $domainNames lowercase */
        $domainNames = [];

        foreach ($domains as $domain) {
            $domainNames[strtolower(trim($domain->name))] = true;
        }

        /** @var array<string, string> $claimed lowercase mailbox => account */
        $claimed = [];

        /** @var array<string, MailboxIdentity> $identities lowercase stored name => identity */
        $identities = [];

        /** @var array<string, DomainName> $accountDomains stored name => domain of record */
        $accountDomains = [];

        /** @var array<string, true> $accountNames lowercase stored names, resolvable or not */
        $accountNames = [];

        /** @var array<string, true> $unresolvable lowercase stored names already reported */
        $unresolvable = [];

        foreach ($accounts as $account) {
            $accountNames[strtolower($account->username)] = true;
            $mailbox = strtolower(self::USER_PREFIX . $account->username);

            if (trim($account->domain) === '') {
                // The Cyrus administrator's own row carries no domain. It is not
                // an account a domain is responsible for, and a mailbox under its
                // name is not an orphan.
                $claimed[$mailbox] = $account->username;

                continue;
            }

            $identity = self::identityOf($account);

            if ($identity === null) {
                $findings[] = new Finding(
                    FindingCategory::UnresolvableAccount,
                    $account->username,
                    $account->domain,
                    reason: 'name_does_not_match_domain',
                );
                // Its mailbox is still its mailbox; reporting it as an orphan as
                // well would describe one problem twice. The same holds for a
                // destination that names it (see virtualFindings()).
                $claimed[$mailbox] = $account->username;
                $unresolvable[strtolower($account->username)] = true;

                continue;
            }

            $identities[strtolower($account->username)] = $identity;
            $accountDomains[$account->username] = $identity->domain;
            $claimed[$mailbox] = $account->username;

            if (!isset($mailboxes[$mailbox])) {
                $findings[] = new Finding(
                    FindingCategory::AccountWithoutMailbox,
                    $account->username,
                    $identity->domain->toString(),
                    self::USER_PREFIX . $account->username,
                );
            }

            foreach (DottedNamespace::subdomainsReachedBy($identity) as $subdomain) {
                if (isset($domainNames[strtolower($subdomain->toString())])) {
                    $findings[] = new Finding(
                        FindingCategory::AmbiguousUsername,
                        $account->username,
                        $identity->domain->toString(),
                        $subdomain->toString(),
                        'subdomain_exists',
                    );
                }
            }
        }

        // Shadows: the other scheme's mailbox of an account, claimed by no row.
        foreach ($identities as $identity) {
            $other = $identity->withScheme(
                $identity->scheme === NamingScheme::Dotted ? NamingScheme::Fqun : NamingScheme::Dotted,
            );
            $shadow = strtolower($other->imapMailbox());

            if (isset($mailboxes[$shadow]) && !isset($claimed[$shadow])) {
                $findings[] = new Finding(
                    FindingCategory::ShadowMailbox,
                    $mailboxes[$shadow],
                    $identity->domain->toString(),
                    $identity->cyrusUsername(),
                    $other->scheme->value,
                );
                $claimed[$shadow] = $identity->cyrusUsername();
            }
        }

        foreach ($mailboxes as $lower => $name) {
            if (!isset($claimed[$lower])) {
                $findings[] = new Finding(FindingCategory::MailboxWithoutAccount, $name);
            }
        }

        $findings = [
            ...$findings,
            ...$this->virtualFindings($rows, $accountDomains, $accountNames, $unresolvable, $identities, $domains, $lists),
        ];

        return new ReconciliationReport(
            $this->clock->now(),
            count($accounts),
            count($mailboxes),
            count($rows),
            $findings,
        );
    }

    /**
     * Every user root Cyrus has, lowercase name => name as listed.
     *
     * @return array<string, string>
     */
    private function mailboxes(): array
    {
        $mailboxes = [];

        foreach ($this->mailstore->listMailboxes(self::PATTERN) as $entry) {
            if (!$entry->isUserRoot()) {
                continue;
            }

            $mailboxes[strtolower($entry->name)] = $entry->name;
        }

        return $mailboxes;
    }

    /**
     * @param list<VirtualRow>               $rows
     * @param array<string, DomainName>      $accountDomains
     * @param array<string, true>            $accountNames
     * @param array<string, true>            $unresolvable
     * @param array<string, MailboxIdentity> $identities
     * @param list<InventoryDomain>          $domains
     * @param list<string>                   $lists
     *
     * @return list<Finding>
     */
    private function virtualFindings(
        array $rows,
        array $accountDomains,
        array $accountNames,
        array $unresolvable,
        array $identities,
        array $domains,
        array $lists,
    ): array {
        /** @var array<string, true> $local lowercase names of Cyrus-delivered domains */
        $local = [];

        /** @var array<string, true> $placeholders lowercase domain names (U-106) */
        $placeholders = [];

        foreach ($domains as $domain) {
            $name = strtolower(trim($domain->name));
            $placeholders[$name] = true;

            if ($domain->isDeliveredLocally()) {
                $local[$name] = true;
            }
        }

        /** @var array<string, true> $answered lowercase addresses something delivers */
        $answered = [];

        /** @var array<string, true> $catchalls lowercase domains with a catchall */
        $catchalls = [];

        foreach ($identities as $lower => $identity) {
            $answered[strtolower($identity->address()->toString())] = true;
            $answered[$lower] = true;
        }

        // An account already reported as unresolvable: a destination naming
        // it is the same problem, not a second one. Its address is unknown —
        // that is the problem — so only the stored name counts.
        $answered += $unresolvable;

        foreach ($rows as $row) {
            if (!$row->isEnabled()) {
                // Postfix delivers only `status = 1`; a disabled row answers
                // nothing (U-124).
                continue;
            }

            $alias = strtolower(trim($row->alias));

            if (str_starts_with($alias, '@')) {
                $catchalls[substr($alias, 1)] = true;
            } elseif ($alias !== '') {
                $answered[$alias] = true;
            }
        }

        foreach ($lists as $address) {
            $answered[strtolower(trim($address))] = true;
        }

        $findings = [];
        $entries = (new VirtualEntryClassifier())->classifyAll(
            $rows,
            new MailingListRecognition($this->delivery->transportDomain, $lists),
            $accountDomains,
        );

        foreach ($entries as $entry) {
            $row = $entry->row();
            $subject = trim($row->alias) === '' ? '' : trim($row->alias);
            $domain = self::domainOfAlias($subject);

            if ($entry instanceof UnknownVirtualEntry) {
                $findings[] = new Finding(
                    FindingCategory::VirtualUnclassified,
                    $subject,
                    $domain,
                    trim($row->dest) === '' ? null : trim($row->dest),
                    $entry->reason->value,
                );

                continue;
            }

            if ($entry instanceof MailingListEntry) {
                // A list row names no account: in the legacy form `username`
                // and `dest` are the list key, in the transport form `dest` is
                // on the pseudo-domain. Neither is a missing owner or target.
                continue;
            }

            $owner = strtolower(trim($row->username));

            if ($owner !== '' && !isset($accountNames[$owner]) && !isset($placeholders[$owner])) {
                $findings[] = new Finding(
                    FindingCategory::VirtualOwnerMissing,
                    $subject,
                    $domain,
                    trim($row->username),
                    'no_account',
                );
            }

            $seen = [];

            foreach ($entry->destinations()->all() as $destination) {
                $lower = strtolower($destination);

                if (isset($seen[$lower])) {
                    continue;
                }

                $seen[$lower] = true;

                if (!str_contains($destination, '@')) {
                    if (!isset($accountNames[$lower])) {
                        $findings[] = new Finding(
                            FindingCategory::VirtualTargetMissing,
                            $subject,
                            $domain,
                            $destination,
                            'no_account',
                        );
                    }

                    continue;
                }

                $targetDomain = substr($lower, (int) strrpos($lower, '@') + 1);

                if (isset($local[$targetDomain])
                    && !isset($answered[$lower])
                    && !isset($answered[self::withoutExtension($lower)])
                    && !isset($catchalls[$targetDomain])
                ) {
                    $findings[] = new Finding(
                        FindingCategory::VirtualTargetMissing,
                        $subject,
                        $domain,
                        $destination,
                        'no_address',
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * The address Postfix falls back to for `anna+news@example.test`: the
     * local part up to the first {@see LocalPart::RECIPIENT_DELIMITER}
     * (`recipient_delimiter = +`, F4 point 6). Used only as a second lookup
     * after the exact one — a row for `anna+news@…` itself still answers
     * first (U-124).
     */
    private static function withoutExtension(string $address): string
    {
        $at = (int) strrpos($address, '@');
        $delimiter = strpos($address, LocalPart::RECIPIENT_DELIMITER);

        if ($delimiter === false || $delimiter === 0 || $delimiter > $at) {
            return $address;
        }

        return substr($address, 0, $delimiter) . substr($address, $at);
    }

    private static function identityOf(InventoryAccount $account): ?MailboxIdentity
    {
        try {
            return MailboxIdentity::fromStoredUsername(
                $account->username,
                DomainName::fromStoredValue(trim($account->domain)),
            );
        } catch (DomainError) {
            return null;
        }
    }

    /**
     * The part after the last `@` of an alias or catchall — an alias always has
     * one, which is what makes this the allowed split rather than a guess.
     */
    private static function domainOfAlias(string $alias): ?string
    {
        $at = strrpos($alias, '@');

        if ($at === false) {
            return null;
        }

        $domain = substr($alias, $at + 1);

        return $domain === '' ? null : $domain;
    }
}

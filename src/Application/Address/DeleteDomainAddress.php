<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\ReservedLocalPart;
use Mailadmin\Domain\Model\Virtual\MailboxAddress;
use Mailadmin\Domain\Model\Virtual\MailingListEntry;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `DELETE /domains/{domain}/addresses/{entry}` — one row, whatever its type
 * (U-103).
 *
 * This is the way to remove what fits no pattern, and the way to clear the
 * leftovers that keep a domain from being deleted (`domain.still_has_addresses`,
 * U-95) or its name from being created again (U-99). It takes exactly one row,
 * identified by {@see EntryKey}: a row that changed in the meantime has another
 * key and is not found.
 *
 * **An account's own address row is refused** with `address.not_editable`. It
 * is what delivers into the mailbox; removing it alone leaves an account that
 * receives nothing. It goes with the account (`DELETE /accounts/{account}`).
 *
 * **A mailing list's row is refused** the same way, with the reason
 * `managed_by_mailing_list`: removed alone, it leaves a `lists` row nothing
 * delivers to. It goes with the list (`DELETE /lists/{address}`).
 *
 * A row the domain's enabled catchall depends on is refused with
 * `address.needed_by_catchall` (U-112).
 *
 * **A bare key is checked against its own domain** (U-126). A row whose `alias`
 * has no `@` is a lookup key, and the key belongs to the domain of the account
 * it names — not to the domain of the account in `username`. Every domain the
 * key names must be in the actor's scope, or the row is not found. A key that
 * names no account belongs to no domain; only a superuser removes it
 * (`address.not_editable`), because whatever routes through it may come from
 * anywhere.
 */
final readonly class DeleteDomainAddress
{
    public const string OPERATION = 'address.delete';

    public function __construct(
        private AddressReader $reader,
        private DomainStore $domains,
        private RowWriter $writer,
        private LoopGuard $loops,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     * @throws \Throwable
     */
    public function handle(ActorContext $actor, DomainName $domain, string $entry, bool $confirmReserved): void
    {
        AddressPolicy::requireDeleteIn($actor, $domain);

        if (!EntryKey::isWellFormed($entry)) {
            throw AddressOperationFailed::notFound('such entry');
        }

        $this->domains->withNamespaceLock(function () use ($actor, $domain, $entry, $confirmReserved): void {
            $rows = $this->reader->ofDomain($domain);
            $found = $rows->find($entry) ?? throw AddressOperationFailed::notFound('such entry');

            $this->requireKeyInScope($actor, $found->row());

            if ($found instanceof MailboxAddress) {
                throw AddressOperationFailed::notEditable(
                    sprintf('The address "%s"', $found->alias()),
                    'it delivers into an account\'s mailbox and goes when the account is deleted',
                );
            }

            if ($found instanceof MailingListEntry) {
                throw AddressOperationFailed::managedByMailingList($found->alias());
            }

            if (!$confirmReserved && $found->isEnabled() && self::isMandatory($found->alias())) {
                throw AddressOperationFailed::reserved($found->alias());
            }

            $this->loops->requireCatchallKeepsWorking($domain, $found->alias(), [$found->row()]);

            $this->writer->replace(
                self::OPERATION,
                $found->alias(),
                [$found->row()],
                [],
                AuditEntry::removal(
                    $actor,
                    self::OPERATION,
                    'address',
                    $found->alias(),
                    before: AddressView::entry($found, $rows),
                    domain: $domain,
                ),
            );
        });
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requireKeyInScope(ActorContext $actor, VirtualRow $row): void
    {
        if (str_contains($row->alias, '@')) {
            return;
        }

        $owners = $this->reader->domainsOfKey($row->alias);

        foreach ($owners as $owner) {
            AddressPolicy::requireDeleteIn($actor, $owner);
        }

        if ($owners === [] && !$actor->isSuperuser()) {
            throw AddressOperationFailed::notEditable(
                sprintf('The entry "%s"', $row->alias),
                'its key is no address and names no account, so it belongs to no domain; only a superuser can remove it',
            );
        }
    }

    private static function isMandatory(string $alias): bool
    {
        if (!EmailAddress::looksLikeAddress($alias)) {
            return false;
        }

        try {
            return ReservedLocalPart::isMandatory(EmailAddress::parseStored($alias)->localPart);
        } catch (\Mailadmin\Domain\Exception\DomainError) {
            return false;
        }
    }
}

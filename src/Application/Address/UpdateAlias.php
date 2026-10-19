<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\ReservedLocalPart;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `PATCH /aliases/{alias}` — destinations, status, sending account.
 *
 * The alias is rewritten as **one** row, whatever number it had (U-105): the
 * new row goes in first, the old ones come out after it, and the audit entry
 * is part of the same unit. That is only done when every row under the address
 * is an alias with the same `username` and `status` — otherwise `address.not_editable`,
 * and the rows are shown, not merged.
 *
 * **The sending account never widens on its own** (U-106). Without
 * `sender_account` in the body the stored `username` stays — unless it is an
 * account that no longer receives the alias's mail, in which case it falls
 * back to the placeholder: a right to send as an address one no longer reads
 * is not kept by accident.
 *
 * Disabling an alias also withdraws the sending right: `validsenders` only
 * joins rows with `status = 1`.
 *
 * New destinations, or a row that comes back on, must not lead into a loop;
 * and a change must not leave the domain's enabled catchall pointing at an
 * address that no longer delivers (U-112).
 */
final readonly class UpdateAlias
{
    public const string OPERATION = 'alias.update';

    public function __construct(
        private AliasLookup $lookup,
        private AddressReader $reader,
        private DomainStore $domains,
        private DestinationRules $destinations,
        private SenderRules $senders,
        private RowWriter $writer,
        private LoopGuard $loops,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Exception\DomainError
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     * @throws \Throwable
     */
    public function handle(ActorContext $actor, string $alias, AliasChange $change, ?string $ifMatch): AliasState
    {
        $address = AliasLookup::address($alias);
        AddressPolicy::requireWrite($actor, $address);

        return $this->domains->withNamespaceLock(function () use ($actor, $address, $change, $ifMatch): AliasState {
            $state = $this->lookup->existing($address);
            AddressView::requireMatch(AddressView::alias($state), $ifMatch, 'The alias');

            if (!$state->isEditable()) {
                throw AddressOperationFailed::notEditable(
                    sprintf('The alias "%s"', $state->address->toString()),
                    'its rows are not all aliases with the same sending account and status',
                );
            }

            $destinations = $change->destinations === null
                ? $state->destinations()
                : $this->destinations->parse($change->destinations, [$state->address->toString()]);

            $enabled = $change->enabled ?? $state->isEnabled();

            if (!$enabled && $state->isEnabled() && !$change->confirmReserved
                && ReservedLocalPart::isMandatory($state->address->localPart)) {
                throw AddressOperationFailed::reserved($state->address->toString());
            }

            $row = new VirtualRow(
                $state->aliasRows()[0]->alias,
                $destinations->toLegacyString(),
                $this->sender($state, $change, $destinations),
                $enabled ? VirtualRow::STATUS_ENABLED : 0,
            );

            $before = $state->aliasRows();

            if (count($before) === 1 && $before[0]->equals($row)) {
                return $state;
            }

            // A loop can only appear through new destinations or a row that
            // comes back on; a legacy loop does not block an unrelated change.
            if ($enabled && ($change->destinations !== null || !$state->isEnabled())) {
                $this->loops->requireNoLoop($state->address->toString(), $before, [$row]);
            }

            $this->loops->requireCatchallKeepsWorking(
                $state->address->domain,
                $state->address->toString(),
                $before,
                [$row],
            );

            $after = new AliasState($state->address, $this->reader->classify([$row]));

            $this->writer->replace(
                self::OPERATION,
                $state->address->toString(),
                $before,
                [$row],
                AuditEntry::change(
                    $actor,
                    self::OPERATION,
                    'alias',
                    $state->address->toString(),
                    before: AliasRecord::ofAlias($state),
                    after: AliasRecord::ofAlias($after),
                    domain: $state->address->domain,
                ),
            );

            return $this->lookup->existing($state->address);
        });
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Exception\InvalidAddress
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function sender(AliasState $state, AliasChange $change, DestinationList $destinations): string
    {
        if ($change->senderAccountGiven) {
            return $change->senderAccount === null
                ? $this->senders->placeholder($state->address->domain)
                : $this->senders->explicit($change->senderAccount, $destinations);
        }

        $current = (string) $state->storedUsername();

        if ($state->rows->isAccount($current) && !$this->senders->stillReceives($current, $destinations)) {
            return $this->senders->placeholder($state->address->domain);
        }

        return $current;
    }
}

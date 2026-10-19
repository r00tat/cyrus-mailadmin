<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * `PUT /accounts/{account}/forwards` — the account's forward as one row in
 * `virtual`, never as a Sieve `redirect` (U-107).
 *
 * `keep_copy` is written the way every legacy row writes it: the account's own
 * user name at the end of the destinations. In the `fqun` scheme the user name
 * *is* the address, the account's address row answers the same key, and the
 * mailbox gets the mail whatever the forward says — so `keep_copy: false` is
 * refused there with `forward.copy_required` rather than accepted and not
 * honoured.
 *
 * A forward without a copy also means the vacation notice never answers: the
 * mail never reaches the mailbox, and Sieve runs at delivery into it.
 *
 * A forward to another account whose own forward points back is a loop
 * Postfix defers; it is refused (U-112). With `keep_copy` on both sides it is
 * none — each address expands into itself, and that ends the chain.
 */
final readonly class SetForwards
{
    public const string OPERATION = 'forward.set';

    public function __construct(
        private AccountTarget $accounts,
        private AddressReader $reader,
        private DomainStore $domains,
        private DestinationRules $destinations,
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
    public function handle(ActorContext $actor, string $account, ForwardChange $change, ?string $ifMatch): ForwardState
    {
        [$record] = $this->accounts->forWrite($actor, $account);

        return $this->domains->withNamespaceLock(function () use ($actor, $record, $change, $ifMatch): ForwardState {
            $record = $this->accounts->stillThere($record);
            $state = new ForwardState($record, $this->reader->forwardsOf($record->storedUsername));
            AddressView::requireMatch(AddressView::forwards($state), $ifMatch, 'The forward');

            if (!$state->isEditable()) {
                throw AddressOperationFailed::notEditable(
                    sprintf('The forward of "%s"', $record->storedUsername),
                    'a row under the account\'s name is not a forward',
                );
            }

            if (!$change->keepCopy && $state->copyRequired()) {
                throw AddressOperationFailed::copyRequired($record->storedUsername);
            }

            $destinations = $this->destinations->parse($change->destinations, $state->ownNames());
            $written = $change->keepCopy
                ? DestinationList::of(...[...$destinations->all(), $record->storedUsername])
                : $destinations;

            $row = new VirtualRow(
                $record->storedUsername,
                $written->toLegacyString(),
                '',
                $change->enabled ? VirtualRow::STATUS_ENABLED : 0,
            );

            $before = $state->forwardRows();

            if (count($before) === 1 && $before[0]->equals($row)) {
                return $state;
            }

            if ($row->isEnabled()) {
                $this->loops->requireNoLoop($record->storedUsername, $before, [$row]);
            }

            $after = new ForwardState($record, $this->reader->classify([$row]));

            $this->writer->replace(
                self::OPERATION,
                $record->storedUsername,
                $before,
                [$row],
                $state->isConfigured()
                    ? AuditEntry::change(
                        $actor,
                        self::OPERATION,
                        'forward',
                        $record->storedUsername,
                        before: AliasRecord::ofForwards($state),
                        after: AliasRecord::ofForwards($after),
                        domain: $record->domain,
                    )
                    : AuditEntry::creation(
                        $actor,
                        self::OPERATION,
                        'forward',
                        $record->storedUsername,
                        after: AliasRecord::ofForwards($after),
                        domain: $record->domain,
                    ),
            );

            return new ForwardState($record, $this->reader->forwardsOf($record->storedUsername));
        });
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;

/**
 * `DELETE /accounts/{account}/forwards` — the account's forward rows.
 * Idempotent. A row under the account's name that is not a forward stays
 * (U-22); it is removed through `DELETE /domains/{domain}/addresses/{entry}`.
 */
final readonly class DeleteForwards
{
    public const string OPERATION = 'forward.delete';

    public function __construct(
        private AccountTarget $accounts,
        private AddressReader $reader,
        private DomainStore $domains,
        private RowWriter $writer,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     * @throws \Throwable
     */
    public function handle(ActorContext $actor, string $account, ?string $ifMatch): void
    {
        [$record] = $this->accounts->forDelete($actor, $account);

        $this->domains->withNamespaceLock(function () use ($actor, $record, $ifMatch): void {
            $record = $this->accounts->stillThere($record);
            $state = new ForwardState($record, $this->reader->forwardsOf($record->storedUsername));
            AddressView::requireMatch(AddressView::forwards($state), $ifMatch, 'The forward');

            if (!$state->isConfigured()) {
                return;
            }

            $this->writer->replace(
                self::OPERATION,
                $record->storedUsername,
                $state->forwardRows(),
                [],
                AuditEntry::removal(
                    $actor,
                    self::OPERATION,
                    'forward',
                    $record->storedUsername,
                    before: AliasRecord::ofForwards($state),
                    domain: $record->domain,
                ),
            );
        });
    }
}

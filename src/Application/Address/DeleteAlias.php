<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\ReservedLocalPart;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `DELETE /aliases/{alias}` — every alias row under the address.
 *
 * Rows of another type under the same address — an account's own address row,
 * a row that fits no pattern — stay: they are not the alias, and an unknown
 * row is never removed as a side effect (U-22). `postmaster` and `abuse` need
 * `confirm_reserved` (U-109). An address the domain's enabled catchall
 * delivers to cannot go while it does (`address.needed_by_catchall`, U-112).
 */
final readonly class DeleteAlias
{
    public const string OPERATION = 'alias.delete';

    public function __construct(
        private AliasLookup $lookup,
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
    public function handle(ActorContext $actor, string $alias, bool $confirmReserved, ?string $ifMatch): void
    {
        $address = AliasLookup::address($alias);
        AddressPolicy::requireDelete($actor, $address);

        $this->domains->withNamespaceLock(function () use ($actor, $address, $confirmReserved, $ifMatch): void {
            $state = $this->lookup->existing($address);
            AddressView::requireMatch(AddressView::alias($state), $ifMatch, 'The alias');

            if (!$confirmReserved && ReservedLocalPart::isMandatory($state->address->localPart)) {
                throw AddressOperationFailed::reserved($state->address->toString());
            }

            $this->loops->requireCatchallKeepsWorking(
                $state->address->domain,
                $state->address->toString(),
                $state->aliasRows(),
            );

            $this->writer->replace(
                self::OPERATION,
                $state->address->toString(),
                $state->aliasRows(),
                [],
                AuditEntry::removal(
                    $actor,
                    self::OPERATION,
                    'alias',
                    $state->address->toString(),
                    before: AliasRecord::ofAlias($state),
                    domain: $state->address->domain,
                ),
            );
        });
    }
}

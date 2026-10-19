<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `PUT /domains/{domain}/catchall` — creates or replaces the catchall as one
 * row (U-105, U-108).
 *
 * **A destination in the catchall's own domain must already be an enabled
 * address** (or an account). Otherwise Postfix resolves it through the
 * catchall itself, it expands into itself and is delivered to a mailbox that
 * does not exist — every unknown address of the domain bounces. Disabled rows
 * do not count: Postfix does not read them (U-112). A destination that leads
 * into a loop through other rows is refused as well.
 *
 * **Nobody may send as the catchall unless the body says so** (U-106). The
 * legacy `catchall.php` wrote the first destination's account into
 * `username`, which through `validsenders` let that account send as
 * `@domain` — every address of the domain. A catchall row with an empty
 * `username` is still a catchall (the `@` is decided first) and grants
 * nothing. A sending account already stored is kept as long as it still
 * receives.
 */
final readonly class SetCatchall
{
    public const string OPERATION = 'catchall.set';

    public function __construct(
        private AddressReader $reader,
        private CatchallLookup $lookup,
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
    public function handle(ActorContext $actor, DomainName $domain, CatchallChange $change, ?string $ifMatch): CatchallState
    {
        AddressPolicy::requireCatchallWrite($actor, $domain);

        return $this->domains->withNamespaceLock(function () use ($actor, $domain, $change, $ifMatch): CatchallState {
            if (!$this->domains->exists($domain)) {
                throw AddressOperationFailed::notFound('such domain');
            }

            $state = $this->lookup->of($domain);
            AddressView::requireMatch(AddressView::catchall($state), $ifMatch, 'The catchall');

            if (!$state->isEditable()) {
                throw AddressOperationFailed::notEditable(
                    sprintf('The catchall of "%s"', $domain->toString()),
                    'its rows are not all catchall rows with the same sending account',
                );
            }

            $alias = CatchallState::alias($domain);
            $destinations = $this->destinations->parse($change->destinations, [$alias]);

            $row = new VirtualRow(
                $state->isConfigured() ? $state->catchallRows()[0]->alias : $alias,
                $destinations->toLegacyString(),
                $this->sender($state, $change, $destinations),
                $change->enabled ? VirtualRow::STATUS_ENABLED : 0,
            );

            $before = $state->catchallRows();

            if (count($before) === 1 && $before[0]->equals($row)) {
                return $state;
            }

            $this->requireDelivery($domain, $before, $row);

            $after = new CatchallState($domain, $this->reader->classify([$row]));

            $this->writer->replace(
                self::OPERATION,
                $alias,
                $before,
                [$row],
                $state->isConfigured()
                    ? AuditEntry::change(
                        $actor,
                        self::OPERATION,
                        'catchall',
                        $alias,
                        before: AliasRecord::ofCatchall($state),
                        after: AliasRecord::ofCatchall($after),
                        domain: $domain,
                    )
                    : AuditEntry::creation(
                        $actor,
                        self::OPERATION,
                        'catchall',
                        $alias,
                        after: AliasRecord::ofCatchall($after),
                        domain: $domain,
                    ),
            );

            return $this->lookup->of($domain);
        });
    }

    /**
     * Checked as if the row were enabled, so that switching it on later
     * cannot be what breaks the domain (U-108, U-112).
     *
     * @param list<VirtualRow> $before
     *
     * @throws InvalidAddress
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requireDelivery(DomainName $domain, array $before, VirtualRow $row): void
    {
        $probe = new VirtualRow($row->alias, $row->dest, $row->username, VirtualRow::STATUS_ENABLED);
        $problem = $this->loops->catchallProblem($domain, $before, [$probe]);

        if ($problem !== null) {
            throw InvalidAddress::unusableDestination($problem);
        }
    }

    /**
     * @throws AddressOperationFailed
     * @throws InvalidAddress
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function sender(CatchallState $state, CatchallChange $change, DestinationList $destinations): string
    {
        if ($change->senderAccountGiven) {
            return $change->senderAccount === null
                ? ''
                : $this->senders->explicit($change->senderAccount, $destinations);
        }

        $current = $state->senderAccount();

        return $current !== null && $this->senders->stillReceives($current, $destinations) ? $current : '';
    }
}

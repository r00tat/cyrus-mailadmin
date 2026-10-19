<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `DELETE /domains/{domain}/catchall` — every catchall row of the domain.
 * Idempotent: a domain without a catchall answers `204` as well.
 */
final readonly class DeleteCatchall
{
    public const string OPERATION = 'catchall.delete';

    public function __construct(
        private CatchallLookup $lookup,
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
    public function handle(ActorContext $actor, DomainName $domain, ?string $ifMatch): void
    {
        AddressPolicy::requireDeleteIn($actor, $domain);

        $this->domains->withNamespaceLock(function () use ($actor, $domain, $ifMatch): void {
            $state = $this->lookup->of($domain);
            AddressView::requireMatch(AddressView::catchall($state), $ifMatch, 'The catchall');

            if (!$state->isConfigured()) {
                return;
            }

            $alias = CatchallState::alias($domain);

            $this->writer->replace(
                self::OPERATION,
                $alias,
                $state->catchallRows(),
                [],
                AuditEntry::removal(
                    $actor,
                    self::OPERATION,
                    'catchall',
                    $alias,
                    before: AliasRecord::ofCatchall($state),
                    domain: $domain,
                ),
            );
        });
    }
}

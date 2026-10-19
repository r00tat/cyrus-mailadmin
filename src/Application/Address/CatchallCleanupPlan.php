<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * The catchall rows an account deletion replaces — computed by
 * {@see CatchallCleanup}, written by `DeleteAccount` in its database step.
 *
 * `$remove` are the rows as they are now; `$insert` their replacements, in
 * the same order, one fewer for every row that loses all its destinations.
 */
final readonly class CatchallCleanupPlan
{
    /**
     * @param list<VirtualRow> $remove
     * @param list<VirtualRow> $insert
     * @param list<string>     $removedDestinations as they were stored
     */
    public function __construct(
        public CatchallState $before,
        public CatchallState $after,
        public array $remove,
        public array $insert,
        public array $removedDestinations,
    ) {
    }

    public static function nothing(CatchallState $current): self
    {
        return new self($current, $current, [], [], []);
    }

    public function changesAnything(): bool
    {
        return $this->remove !== [];
    }

    /**
     * Was the whole catchall removed — no row of it left?
     */
    public function removesTheCatchall(): bool
    {
        return $this->changesAnything() && !$this->after->isConfigured();
    }

    /**
     * The audit entry for the catchall: `catchall.delete` when nothing of it is
     * left, `catchall.set` when it was rewritten. The caller attaches it to the
     * deletion's entry with `partOf()`.
     *
     * @throws \Mailadmin\Application\Audit\InvalidAuditEntry
     */
    public function auditEntry(ActorContext $actor): ?AuditEntry
    {
        if (!$this->changesAnything()) {
            return null;
        }

        $alias = CatchallState::alias($this->before->domain);
        $before = [
            ...AliasRecord::ofCatchall($this->before),
            // Why the catchall changed is the parent entry (`account.delete`);
            // what went is this list.
            'removed_destinations' => $this->removedDestinations,
        ];

        if ($this->removesTheCatchall()) {
            return AuditEntry::removal(
                $actor,
                DeleteCatchall::OPERATION,
                'catchall',
                $alias,
                before: $before,
                domain: $this->before->domain,
            );
        }

        return AuditEntry::change(
            $actor,
            SetCatchall::OPERATION,
            'catchall',
            $alias,
            before: $before,
            after: AliasRecord::ofCatchall($this->after),
            domain: $this->before->domain,
        );
    }
}

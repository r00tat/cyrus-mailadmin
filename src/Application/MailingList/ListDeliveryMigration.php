<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

/**
 * The protocol of one run of {@see MigrateListDelivery}: a line per list, in
 * address order, and the totals.
 */
final readonly class ListDeliveryMigration
{
    /**
     * @param list<ListDeliveryOutcome> $outcomes
     */
    public function __construct(
        public bool $revert,
        public bool $dryRun,
        public array $outcomes,
    ) {
    }

    /**
     * How many lists ended in each result, every result present.
     *
     * @return array<string, int>
     */
    public function totals(): array
    {
        $totals = [];

        foreach (ListDeliveryResult::cases() as $result) {
            $totals[$result->value] = 0;
        }

        foreach ($this->outcomes as $outcome) {
            ++$totals[$outcome->result->value];
        }

        return $totals;
    }

    public function hasFailures(): bool
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->result === ListDeliveryResult::Failed) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What one reconciliation run found, with what it looked at.
 *
 * The counts of what was checked are part of the answer on purpose: "no
 * findings" over zero mailboxes is a different statement from "no findings"
 * over four thousand.
 */
final readonly class ReconciliationReport
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public DateTimeImmutable $checkedAt,
        public int $accounts,
        public int $mailboxes,
        public int $virtualRows,
        public array $findings,
    ) {
    }

    /**
     * @return array<string, int> every category, with zero where nothing was found
     */
    public function summary(): array
    {
        $summary = [];

        foreach (FindingCategory::cases() as $category) {
            $summary[$category->value] = 0;
        }

        foreach ($this->findings as $finding) {
            ++$summary[$finding->category->value];
        }

        return $summary;
    }

    /**
     * @return list<Finding>
     */
    public function of(FindingCategory $category): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->category === $category,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toRepresentation(): array
    {
        return [
            'checked_at' => $this->checkedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'checked' => [
                'accounts' => $this->accounts,
                'mailboxes' => $this->mailboxes,
                'virtual_rows' => $this->virtualRows,
            ],
            'summary' => $this->summary(),
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toRepresentation(),
                $this->findings,
            ),
        ];
    }
}

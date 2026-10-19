<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Sieve\SieveRuleSet;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Domain\Sieve\VacationRule;

/**
 * The vacation notice of one account, as read from its active Sieve script.
 */
final readonly class VacationState
{
    private function __construct(
        public string $account,
        public VacationKind $kind,
        public ?SieveScript $script,
        public ?SieveRuleSet $ruleSet,
    ) {
    }

    public static function of(string $account, ?SieveScript $active): self
    {
        if ($active === null) {
            return new self($account, VacationKind::None, null, null);
        }

        if ($active->isForeign()) {
            return new self($account, VacationKind::Foreign, $active, null);
        }

        $rules = $active->ruleSet();

        if ($rules === null) {
            return new self($account, VacationKind::Unreadable, $active, null);
        }

        return new self(
            $account,
            $rules->hasVacation() ? VacationKind::Active : VacationKind::None,
            $active,
            $rules,
        );
    }

    public function rule(): ?VacationRule
    {
        return $this->ruleSet?->vacation();
    }

    /**
     * The rule set a change starts from: the decoded one, or nothing — a
     * foreign or unreadable script is replaced, never merged (architecture
     * 9.4).
     */
    public function baseRules(): SieveRuleSet
    {
        return $this->ruleSet ?? SieveRuleSet::empty();
    }
}

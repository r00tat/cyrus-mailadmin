<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

use Mailadmin\Domain\Exception\InvalidSieveRule;

/**
 * Conditions plus what happens when they match.
 *
 * The invalid combinations cannot be constructed: no conditions, `fileinto`
 * without a folder, a folder on an action that takes none.
 */
final readonly class FilterRule
{
    /**
     * @param list<FilterCondition> $conditions
     */
    private function __construct(
        public array $conditions,
        public ConditionJoin $join,
        public FilterAction $action,
        public ?string $folder,
        public bool $stopAfterMatch,
    ) {
    }

    /**
     * @throws InvalidSieveRule
     */
    public static function fileInto(
        string $folder,
        ConditionJoin $join,
        FilterCondition ...$conditions,
    ): self {
        $target = trim($folder);

        if ($target === '') {
            throw InvalidSieveRule::folderRequired();
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            throw InvalidSieveRule::unusableFolder($target);
        }

        return self::create(FilterAction::FileInto, $target, $join, $conditions);
    }

    /**
     * @throws InvalidSieveRule
     */
    public static function discard(ConditionJoin $join, FilterCondition ...$conditions): self
    {
        return self::create(FilterAction::Discard, null, $join, $conditions);
    }

    /**
     * @throws InvalidSieveRule
     */
    public static function keep(ConditionJoin $join, FilterCondition ...$conditions): self
    {
        return self::create(FilterAction::Keep, null, $join, $conditions);
    }

    /**
     * Should evaluation stop after this rule matched? On by default: a message
     * that was filed once should not be filed again by a later rule.
     */
    public function continuingAfterMatch(): self
    {
        return new self($this->conditions, $this->join, $this->action, $this->folder, false);
    }

    /**
     * @return list<string>
     */
    public function requiredExtensions(): array
    {
        return $this->action->requiredExtensions();
    }

    /**
     * @param array<array-key, FilterCondition> $conditions
     *
     * @throws InvalidSieveRule
     */
    private static function create(
        FilterAction $action,
        ?string $folder,
        ConditionJoin $join,
        array $conditions,
    ): self {
        if ($conditions === []) {
            throw InvalidSieveRule::emptyConditionSet();
        }

        if ($action->requiresFolder() && $folder === null) {
            throw InvalidSieveRule::folderRequired();
        }

        if (!$action->requiresFolder() && $folder !== null) {
            throw InvalidSieveRule::folderNotAllowed($action->value);
        }

        return new self(array_values($conditions), $join, $action, $folder, true);
    }
}

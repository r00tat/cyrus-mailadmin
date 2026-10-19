<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

use Mailadmin\Domain\Exception\InvalidSieveRule;

/**
 * One test on one header, e.g. „subject contains invoice".
 */
final readonly class FilterCondition
{
    private function __construct(
        public string $header,
        public MatchType $matchType,
        public string $value,
    ) {
    }

    /**
     * @throws InvalidSieveRule
     */
    public static function on(string $header, MatchType $matchType, string $value): self
    {
        $name = trim($header);

        if ($name === '') {
            throw InvalidSieveRule::emptyHeaderName();
        }

        // RFC 5322 field names: printable ASCII without ":".
        if (preg_match('/^[!-9;-~]+$/', $name) !== 1) {
            throw InvalidSieveRule::unusableHeaderName($name);
        }

        if ($value === '') {
            throw InvalidSieveRule::emptyConditionValue($name);
        }

        return new self($name, $matchType, $value);
    }

    /**
     * @throws InvalidSieveRule
     */
    public static function subjectContains(string $value): self
    {
        return self::on('subject', MatchType::Contains, $value);
    }

    /**
     * @throws InvalidSieveRule
     */
    public static function fromContains(string $value): self
    {
        return self::on('from', MatchType::Contains, $value);
    }
}

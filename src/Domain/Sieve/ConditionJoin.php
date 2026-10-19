<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

/**
 * How the conditions of a filter rule are joined.
 */
enum ConditionJoin: string
{
    case All = 'allof';
    case Any = 'anyof';
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

/**
 * Why a `virtual` row fits none of the four patterns.
 *
 * The reason is shown next to the row (U-22). „We do not understand this" is an
 * honest answer; skipping the row is not, because the row is live in Postfix
 * whether we understand it or not.
 */
enum UnknownReason: string
{
    /** `alias` is empty — nothing can match this row. */
    case NoAlias = 'no_alias';

    /** `dest` is empty — the row names no destination. */
    case NoDestination = 'no_destination';

    /**
     * `alias` is neither an address nor `@domain`, and `username` is set — so
     * it is not a forward either.
     */
    case AliasIsNotAnAddress = 'alias_is_not_an_address';

    /** `alias` is `@` without a domain behind it. */
    case CatchAllWithoutDomain = 'catchall_without_domain';

    /** A value contains something no field of this table should carry. */
    case UnusableValue = 'unusable_value';
}

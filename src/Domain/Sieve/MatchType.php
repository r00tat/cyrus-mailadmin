<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

/**
 * How a filter condition compares (RFC 5228).
 *
 * `:regex` is deliberately absent: it is an extension not every server has, and
 * a regular expression in a generated script is the kind of thing that fails
 * at `PUTSCRIPT` time on someone else's server.
 */
enum MatchType: string
{
    case Is = ':is';
    case Contains = ':contains';

    /** Wildcards `*` and `?`, not regular expressions. */
    case Matches = ':matches';
}

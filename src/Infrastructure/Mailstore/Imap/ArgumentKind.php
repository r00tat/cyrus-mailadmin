<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

/**
 * How an {@see ImapArgument} is put on the wire.
 */
enum ArgumentKind
{
    /** A keyword, verbatim. Only ever a constant from our own source. */
    case Atom;

    /** Quoted when it fits, literal when it does not. */
    case Text;

    /** Always a literal, whatever it looks like. */
    case Literal;

    /** Like {@see Text}, but never rendered in a trace. */
    case Secret;

    /** `(a b c)` — a parenthesised list of further arguments. */
    case ParenList;
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

/**
 * The five states a `virtual` row can be in — four meanings plus „unknown".
 *
 * | Meaning | `alias` | `dest` | `username` |
 * |---|---|---|---|
 * | Address of an account | `local@domain` | Cyrus user | Cyrus user |
 * | Alias onto an account | `local@domain` | Cyrus user | Cyrus user |
 * | Catchall of a domain  | `@domain`      | target     | target     |
 * | Forward to the outside| Cyrus user     | external   | *empty*    |
 *
 * The fifth is not a failure of the model but a property of the data: rows
 * exist that fit none of the four, and they are shown rather than skipped
 * (U-22).
 */
enum VirtualEntryType: string
{
    case MailboxAddress = 'mailbox_address';
    case Alias = 'alias';
    case CatchAll = 'catchall';
    case Forward = 'forward';
    case Unknown = 'unknown';
}

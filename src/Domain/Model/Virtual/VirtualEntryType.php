<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

/**
 * The six states a `virtual` row can be in — five meanings plus „unknown".
 *
 * | Meaning | `alias` | `dest` | `username` |
 * |---|---|---|---|
 * | Address of an account | `local@domain` | Cyrus user | Cyrus user |
 * | Alias onto an account | `local@domain` | Cyrus user | Cyrus user |
 * | Catchall of a domain  | `@domain`      | target     | target     |
 * | Forward to the outside| Cyrus user     | external   | *empty*    |
 * | Mailing list (A5)     | list address   | `<key>@<transport domain>` | *empty* |
 * | Mailing list, legacy  | list address   | `<key>`    | `<key>`    |
 *
 * `<key>` is `REPLACE(address, '@', '.')`. The legacy list row is
 * indistinguishable from an account's address row by its fields alone; it is a
 * list only when `lists` has a row with that address
 * ({@see MailingListRecognition}).
 *
 * „Unknown" is not a failure of the model but a property of the data: rows
 * exist that fit none of the others, and they are shown rather than skipped
 * (U-22).
 */
enum VirtualEntryType: string
{
    case MailboxAddress = 'mailbox_address';
    case Alias = 'alias';
    case CatchAll = 'catchall';
    case Forward = 'forward';
    case MailingList = 'mailing_list';
    case Unknown = 'unknown';
}

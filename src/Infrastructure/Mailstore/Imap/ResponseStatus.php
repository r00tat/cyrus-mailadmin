<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

/**
 * How a command ended.
 *
 * The difference between `NO` and `BAD` is worth keeping: `NO` is the server
 * refusing this request, `BAD` is the server saying the request was malformed —
 * that one is our bug and should surface as such rather than as "the mailbox
 * could not be created".
 */
enum ResponseStatus: string
{
    case Ok = 'OK';
    case No = 'NO';
    case Bad = 'BAD';

    public function isOk(): bool
    {
        return $this === self::Ok;
    }
}

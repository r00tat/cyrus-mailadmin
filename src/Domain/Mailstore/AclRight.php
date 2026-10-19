<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Mailstore;

/**
 * One Cyrus ACL right (RFC 4314 plus the Cyrus legacy letters).
 *
 * The order of the cases is the canonical order an ACL string is rendered in,
 * so that two equal permission sets always produce the same string — an ACL
 * that only differs in the order of its letters would otherwise look like a
 * change in a diff, an ETag (U-19) or an audit entry.
 */
enum AclRight: string
{
    case Lookup = 'l';
    case Read = 'r';
    case Seen = 's';
    case Write = 'w';
    case Insert = 'i';
    case Post = 'p';
    case CreateMailbox = 'k';
    case DeleteMailbox = 'x';
    case DeleteMessage = 't';
    case Expunge = 'e';

    /** Legacy compound: create. Older servers announce `c` instead of `k`/`x`. */
    case LegacyCreate = 'c';

    /** Legacy compound: delete. Older servers announce `d` instead of `t`/`e`. */
    case LegacyDelete = 'd';

    case Administer = 'a';
    case Annotate = 'n';

    public function isLegacyCompound(): bool
    {
        return $this === self::LegacyCreate || $this === self::LegacyDelete;
    }
}

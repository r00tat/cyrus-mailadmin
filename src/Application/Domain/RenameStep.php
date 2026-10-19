<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * One account's part of a domain rename: where its mailbox is, where it has to
 * go, and the exact bytes the database currently stores for it.
 *
 * The stored user name is kept beside the identity rather than derived from it,
 * because it is the key every `UPDATE` matches on and
 * `accountuser.username` is a binary column — a name regenerated from the
 * identity would differ from the stored one in case, and would then update
 * nothing at all while appearing to work.
 */
final readonly class RenameStep
{
    public function __construct(
        public string $storedUsername,
        public MailboxIdentity $from,
        public MailboxIdentity $to,
    ) {
    }
}

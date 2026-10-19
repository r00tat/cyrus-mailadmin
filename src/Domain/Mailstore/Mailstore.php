<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Mailstore;

use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * Everything the application does to a mail store (architecture 9.1).
 *
 * **No IMAP detail crosses this line.** That serves testability first — every
 * use case is tested against {@see \Mailadmin\Infrastructure\Mailstore\InMemoryMailstore}
 * — and a possible Dovecot swap second (E-2). If a method here mentioned a
 * response code or a mailbox string, both of those would be gone.
 *
 * Two things this interface deliberately does **not** do:
 *
 *  - **It does not order operations against the database.** Which comes first,
 *    the mailbox or the row, is architecture 8.1's answer and the use case's
 *    job. A store that tried to be clever about it would make the rule
 *    invisible in the place it has to be readable.
 *  - **It does not decide when a mailbox should exist.** That is A2.
 *
 * Every method may throw a {@see MailstoreFailure}. They are not listed
 * individually per method because there is no method that cannot fail: the
 * other end is a server on a network.
 */
interface Mailstore
{
    /**
     * Creates the account's INBOX.
     *
     * **Strict, not idempotent**: an existing mailbox is a
     * {@see MailstoreFailure} carrying `mailstore.mailbox_exists`, not a quiet
     * success. M2's open point 4 asked for idempotency to help A6's retries, and
     * the answer is no — adopting a mailbox that is already there would hand a
     * new account whatever the previous owner left in it, and that is exactly
     * the orphan U-22 wants *shown* rather than absorbed. A caller that wants
     * the idempotent behaviour asks {@see mailboxExists()} first and says so.
     */
    public function createMailbox(MailboxIdentity $identity): void;

    /**
     * Removes the mailbox and everything below it.
     *
     * Deleting something that is not there succeeds: after the call the
     * postcondition holds either way, and a repair run must be able to finish.
     */
    public function deleteMailbox(MailboxIdentity $identity): void;

    /**
     * Moves a mailbox, folders and all.
     *
     * This is the operation a domain rename and a naming-scheme change are made
     * of (architecture 8.1). Changing only the database row leaves the account
     * unreachable — the mistake the legacy system made.
     */
    public function renameMailbox(MailboxIdentity $from, MailboxIdentity $to): void;

    public function mailboxExists(MailboxIdentity $identity): bool;

    /**
     * Mailboxes matching an IMAP pattern (`*` and `%`), in the server's own
     * naming.
     *
     * Returns {@see MailboxEntry} rather than identities on purpose: a listing
     * cannot know where a local part ends, so it must be able to name a mailbox
     * it cannot interpret. See {@see MailboxEntry}.
     *
     * @return iterable<MailboxEntry>
     */
    public function listMailboxes(string $pattern): iterable;

    /**
     * The mailbox's quota, including its usage where the server reports one.
     *
     * A mailbox with no quota root is {@see Quota::unlimited()} — "no limit
     * configured", which is a different fact from a limit of zero and is kept
     * apart by the value object rather than by a magic number (U-32 d).
     */
    public function getQuota(MailboxIdentity $identity): Quota;

    /**
     * Sets, changes or removes the limit. An unlimited quota removes the quota
     * root; a limit of zero sets a limit of zero.
     */
    public function setQuota(MailboxIdentity $identity, Quota $quota): void;

    public function getAcl(MailboxIdentity $identity): AclSet;

    /**
     * Grants `$subject` exactly `$acl`. An empty {@see Acl} removes the entry.
     */
    public function setAcl(MailboxIdentity $identity, string $subject, Acl $acl): void;

    /**
     * Creates the account's default folders **and subscribes them**, acting in
     * the user's name (architecture 9.3).
     *
     * Not in the interface sketch in architecture 9.1, and added because 9.3
     * requires it and a use case has to be able to exercise it against the
     * double. The legacy system did this by logging in as the new user with
     * their clear-text password; the whole point of the operation living behind
     * this interface is that no implementation is given the chance to.
     *
     * Subscribing as well as creating, because an unsubscribed folder is
     * invisible in most clients and the folder would then exist without doing
     * anything.
     */
    public function createDefaultFolders(MailboxIdentity $identity, DefaultFolders $folders): void;
}

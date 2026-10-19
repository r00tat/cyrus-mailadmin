<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Cyrus;

use Mailadmin\Domain\Mailstore\Acl;
use Mailadmin\Domain\Mailstore\AclSet;
use Mailadmin\Domain\Mailstore\MailboxEntry;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Infrastructure\Mailstore\Imap\CommandFailed;
use Mailadmin\Infrastructure\Mailstore\Imap\CommandResult;
use Mailadmin\Infrastructure\Mailstore\Imap\Connection;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapArgument;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapCommand;
use Mailadmin\Infrastructure\Mailstore\Imap\ModifiedUtf7;
use Mailadmin\Infrastructure\Mailstore\Imap\ResponseLine;
use Mailadmin\Infrastructure\Mailstore\MailboxOperationFailed;

/**
 * {@see Mailstore} against a real Cyrus, over M1's IMAP client.
 *
 * Three things are decided here rather than left to the caller, each because
 * getting it wrong is silent:
 *
 * **1. Refusals are never interpreted from the server's English.**
 * `[ALREADYEXISTS]`, `[NONEXISTENT]` and `[OVERQUOTA]` are contracts (RFC 5530);
 * `NO Mailbox already exists` is a sentence that differs between Cyrus versions.
 *
 * Verified against F5: **Cyrus 3.10.2 sends no response code at all** for
 * `CREATE`, `DELETE`, `SETQUOTA` or `SETACL`. Reading only the code would
 * therefore classify nothing on the very server this is written for, and reading
 * the sentence is the thing M2 forbids. So when there is no code the adapter
 * **asks the server a second question** — {@see existsByName()} — and turns the
 * answer into the fact. One extra round trip, on the error path only, and no
 * list of server phrasings that has to be kept up to date. A refusal that still
 * cannot be explained stays a {@see CommandFailed}: inventing a meaning would be
 * worse than admitting we do not have one.
 *
 * **2. The hierarchy separator is checked once and then trusted.** Every mailbox
 * name the application builds assumes `/` (`unixhierarchysep: yes`). With `.` as
 * the separator, `user.greta.mustermann.example.at` is ambiguous in a way no
 * care downstream can repair, so the adapter refuses to work at all rather than
 * work incorrectly. That is M2's open point 3, answered.
 *
 * **3. Default folders go through proxy authorisation** (architecture 9.3), so
 * they are created and subscribed *as the user* without anybody knowing the
 * user's password. Subscribing matters: an unsubscribed folder is invisible in
 * most clients, and the legacy system subscribed too.
 *
 * The connection comes from the pool, so a listing of fifty accounts costs one
 * TLS handshake and not fifty.
 */
final class CyrusMailstore implements Mailstore
{
    /** The only hierarchy separator this application can work with. */
    public const string SEPARATOR = '/';

    private ?string $verifiedSeparator = null;

    public function __construct(private readonly ConnectionPool $pool)
    {
    }

    public function createMailbox(MailboxIdentity $identity): void
    {
        $name = $identity->imapMailbox();

        try {
            $this->administrative()->execute(
                ImapCommand::of('CREATE', ImapArgument::mailbox($name)),
            );
        } catch (CommandFailed $failure) {
            if ($this->isPresent($failure, $name)) {
                throw MailboxOperationFailed::alreadyExists($name, $failure);
            }

            throw $failure;
        }
    }

    public function deleteMailbox(MailboxIdentity $identity): void
    {
        $name = $identity->imapMailbox();
        $connection = $this->administrative();

        // Cyrus refuses `DELETE user/x` unless the administrator holds `d` on
        // it, and an administrator does not implicitly hold rights on a user's
        // mailbox. The legacy system worked around this by running as the user;
        // granting ourselves the right for the length of the operation is the
        // documented way and leaves nothing behind, because the mailbox is gone
        // a moment later.
        $connection->send(ImapCommand::of(
            'SETACL',
            ImapArgument::mailbox($name),
            ImapArgument::text($this->pool->administratorName()),
            ImapArgument::text(Acl::fullControl()->toString()),
        ));

        $result = $connection->send(ImapCommand::of('DELETE', ImapArgument::mailbox($name)));

        if ($result->isOk()) {
            return;
        }

        // Deleting something that is not there is a success: the postcondition
        // holds, and a repair run (A6) has to be able to finish rather than trip
        // over what an earlier one already did. Cyrus 3.10 says so without a
        // `[NONEXISTENT]`, so the absence is established by asking.
        if (!$this->existsByName($name)) {
            return;
        }

        throw MailboxOperationFailed::deletionRefused(
            $name,
            $result->text,
            CommandFailed::fromResult('DELETE', $result),
        );
    }

    public function renameMailbox(MailboxIdentity $from, MailboxIdentity $to): void
    {
        $source = $from->imapMailbox();
        $target = $to->imapMailbox();

        try {
            // One `RENAME`, folders and all.
            $this->administrative()->execute(ImapCommand::of(
                'RENAME',
                ImapArgument::mailbox($source),
                ImapArgument::mailbox($target),
            ));
        } catch (CommandFailed $failure) {
            if ($this->isPresent($failure, $target)) {
                throw MailboxOperationFailed::alreadyExists($target, $failure);
            }

            if (!$this->existsByName($source)) {
                throw MailboxOperationFailed::notFound($source, $failure);
            }

            // **M2, open point 6, answered against F5: Cyrus 3.10 does move a
            // user mailbox, folders and all, and across domain namespaces too —
            // but only with `allowusermoves: yes`, which is not the default.**
            //
            // With it off, *every* rename of a `user/` mailbox is refused with
            // "Operation is not supported on mailbox". That is a deployment
            // fault rather than a fact about this account, and it deserves to
            // say so: architecture 8.1 makes `renamemailbox` the first half of a
            // domain rename and of a naming-scheme change (E-14), and a caller
            // that mistook this for "that account cannot be renamed" would go on
            // to change the database alone and leave the mailbox unreachable —
            // the legacy system's bug, reproduced by configuration.
            if (self::isUserMailbox($source)) {
                throw MailboxOperationFailed::userMoveRefused($source, $target, $failure);
            }

            throw $failure;
        }
    }

    public function mailboxExists(MailboxIdentity $identity): bool
    {
        return $this->existsByName($identity->imapMailbox());
    }

    /**
     * @return list<MailboxEntry>
     */
    public function listMailboxes(string $pattern): iterable
    {
        $result = $this->administrative()->execute(
            ImapCommand::of('LIST', ImapArgument::text(''), ImapArgument::mailbox($pattern)),
        );

        $entries = [];

        foreach ($this->entriesOf($result) as $entry) {
            // Cyrus keeps deleted mailboxes in `DELETED/…` until it expunges
            // them. Reporting those would fill A6's reconciliation with
            // mailboxes that are on their way out (M2, open point 5).
            if (str_starts_with($entry->name, 'DELETED' . self::SEPARATOR)) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    public function getQuota(MailboxIdentity $identity): Quota
    {
        $name = $identity->imapMailbox();
        $result = $this->administrative()->send(
            ImapCommand::of('GETQUOTAROOT', ImapArgument::mailbox($name)),
        );

        if (!$result->isOk()) {
            $failure = CommandFailed::fromResult('GETQUOTAROOT', $result);

            if ($this->isAbsent($failure, $name)) {
                throw MailboxOperationFailed::notFound($name, $failure);
            }

            throw MailboxOperationFailed::quotaRejected($name, $result->text, $failure);
        }

        foreach ($result->linesWith('QUOTA') as $line) {
            $quota = self::quotaFrom($line);

            if ($quota !== null) {
                return $quota;
            }
        }

        // No quota root, or one without a `STORAGE` resource: no limit is
        // configured. Different from a limit of zero, and the value object keeps
        // the two apart (U-32 d).
        return Quota::unlimited();
    }

    public function setQuota(MailboxIdentity $identity, Quota $quota): void
    {
        $name = $identity->imapMailbox();

        // An unlimited quota is an empty resource list, which is how RFC 2087
        // spells "remove the quota root". Sending `STORAGE 0` instead would set
        // a limit of zero bytes and lock the account out of its own mail.
        $limits = $quota->isUnlimited()
            ? ImapArgument::list()
            : ImapArgument::list(
                ImapArgument::atom('STORAGE'),
                ImapArgument::number($quota->limitKibibytes() ?? 0),
            );

        try {
            $this->administrative()->execute(
                ImapCommand::of('SETQUOTA', ImapArgument::mailbox($name), $limits),
            );
        } catch (CommandFailed $failure) {
            if ($this->isAbsent($failure, $name)) {
                throw MailboxOperationFailed::notFound($name, $failure);
            }

            throw MailboxOperationFailed::quotaRejected($name, $failure->serverText, $failure);
        }
    }

    public function getAcl(MailboxIdentity $identity): AclSet
    {
        $name = $identity->imapMailbox();

        try {
            $result = $this->administrative()->execute(
                ImapCommand::of('GETACL', ImapArgument::mailbox($name)),
            );
        } catch (CommandFailed $failure) {
            if ($this->isAbsent($failure, $name)) {
                throw MailboxOperationFailed::notFound($name, $failure);
            }

            throw MailboxOperationFailed::aclRejected($name, '*', $failure->serverText, $failure);
        }

        $line = $result->firstLineWith('ACL');

        return $line === null ? AclSet::empty() : self::aclSetFrom($line);
    }

    public function setAcl(MailboxIdentity $identity, string $subject, Acl $acl): void
    {
        $name = $identity->imapMailbox();

        // An empty right set is `DELETEACL`, not `SETACL … ""`. Cyrus accepts
        // the latter and stores an entry granting nothing, which then shows up
        // in every listing as a subject with no rights.
        $command = $acl->isEmpty()
            ? ImapCommand::of(
                'DELETEACL',
                ImapArgument::mailbox($name),
                ImapArgument::text($subject),
            )
            : ImapCommand::of(
                'SETACL',
                ImapArgument::mailbox($name),
                ImapArgument::text($subject),
                ImapArgument::text($acl->toString()),
            );

        try {
            $this->administrative()->execute($command);
        } catch (CommandFailed $failure) {
            if ($this->isAbsent($failure, $name)) {
                throw MailboxOperationFailed::notFound($name, $failure);
            }

            throw MailboxOperationFailed::aclRejected($name, $subject, $failure->serverText, $failure);
        }
    }

    public function createDefaultFolders(MailboxIdentity $identity, DefaultFolders $folders): void
    {
        if ($folders->isEmpty()) {
            return;
        }

        // Architecture 9.3: `authcid` is the administrator, `authzid` is the
        // user. No password of the user's is involved, and there is no code path
        // here that could take one.
        $connection = $this->pool->actingFor($identity->cyrusUsername());

        foreach ($folders->names() as $folder) {
            // **`Sent`, not `user/anna.example.test/Sent` and not `INBOX/Sent`.**
            //
            // A proxied session is not an administrative one: it sees the
            // *personal* namespace, whose prefix is empty, so a folder of one's
            // own is named by itself. Both other spellings are accepted by the
            // server and both produce the wrong mailbox — verified against F5:
            //
            //   CREATE user/anna.example.test/Sent -> user/anna.example.test/user/…
            //   CREATE INBOX/Sent                  -> user/anna.example.test/INBOX/Sent
            //
            // Neither fails, and neither is where the user's client looks. This
            // is the kind of mistake that makes every command return OK and the
            // data wrong, which is why it is written down here rather than
            // trusted to memory.
            $relative = trim($folder, '/');
            $absolute = $identity->imapFolder($folder);

            $result = $connection->send(ImapCommand::of('CREATE', ImapArgument::mailbox($relative)));

            if (!$result->isOk() && !$this->existsByName($absolute)) {
                // Creating a folder that is already there is a success: the
                // point is that it exists afterwards, and a repeated call from
                // the repair queue must not fail on its own earlier success.
                throw CommandFailed::fromResult('CREATE', $result);
            }

            // An unsubscribed folder is invisible in most clients, so creating
            // without subscribing does nothing a user can see.
            $connection->execute(ImapCommand::of('SUBSCRIBE', ImapArgument::mailbox($relative)));
        }
    }

    // -----------------------------------------------------------------

    /**
     * @throws MailboxOperationFailed when the server's separator is not `/`
     */
    private function administrative(): Connection
    {
        $connection = $this->pool->administrative();

        if ($this->verifiedSeparator === null) {
            $this->verifiedSeparator = $this->readSeparator($connection);

            if ($this->verifiedSeparator !== self::SEPARATOR) {
                throw MailboxOperationFailed::unsupportedSeparator(
                    $this->pool->endpoint(),
                    $this->verifiedSeparator,
                );
            }
        }

        return $connection;
    }

    /**
     * The personal namespace's separator, from `NAMESPACE` (RFC 2342).
     *
     * A server that does not answer the command at all is taken at the
     * application's word — `/` — rather than refused: Cyrus has announced
     * `NAMESPACE` since 2.x, and a hard failure here would make the adapter
     * unusable against anything that merely lacks the extension.
     */
    private function readSeparator(Connection $connection): string
    {
        if (!$connection->hasCapability('NAMESPACE')) {
            return self::SEPARATOR;
        }

        $result = $connection->send(ImapCommand::of('NAMESPACE'));
        $line = $result->firstLineWith('NAMESPACE');

        if ($line === null) {
            return self::SEPARATOR;
        }

        // `* NAMESPACE (("" "/")) (("Other Users/" "/")) (("Shared Folders/" "/"))`
        // — the personal namespace is the first list, its first pair, and the
        // separator is the second element of that pair.
        $personal = $line->tokens()[2] ?? null;

        if (!is_array($personal)) {
            return self::SEPARATOR;
        }

        $first = $personal[0] ?? null;

        if (!is_array($first) || !is_string($first[1] ?? null)) {
            return self::SEPARATOR;
        }

        return $first[1];
    }

    /**
     * @return list<MailboxEntry>
     */
    private function entriesOf(CommandResult $result): array
    {
        $entries = [];

        foreach ($result->linesWith('LIST') as $line) {
            $tokens = $line->tokens();

            // `* LIST (flags) "sep" "name"` — after the leading `*` and `LIST`.
            $flags = $tokens[2] ?? null;
            $separator = $tokens[3] ?? null;
            $name = $tokens[4] ?? null;

            if (!is_string($name)) {
                continue;
            }

            $entries[] = new MailboxEntry(
                ModifiedUtf7::decode($name),
                is_array($flags) ? array_values(array_filter($flags, 'is_string')) : [],
                is_string($separator) ? $separator : self::SEPARATOR,
            );
        }

        return $entries;
    }

    /**
     * `* QUOTA "root" (STORAGE used limit)` — both numbers in KiB.
     */
    private static function quotaFrom(ResponseLine $line): ?Quota
    {
        $resources = $line->tokens()[3] ?? null;

        if (!is_array($resources)) {
            return null;
        }

        $count = count($resources);

        for ($index = 0; $index + 2 < $count; $index += 3) {
            $resource = $resources[$index] ?? null;
            $used = $resources[$index + 1] ?? null;
            $limit = $resources[$index + 2] ?? null;

            if (!is_string($resource) || strcasecmp($resource, 'STORAGE') !== 0) {
                continue;
            }

            if (!is_string($used) || !is_string($limit)) {
                continue;
            }

            return Quota::ofKibibytes((int) $limit, (int) $used);
        }

        return null;
    }

    /**
     * `* ACL mailbox subject rights subject rights …`
     */
    private static function aclSetFrom(ResponseLine $line): AclSet
    {
        $tokens = $line->tokens();
        // Drop `*`, `ACL` and the mailbox name.
        $pairs = array_slice($tokens, 3);
        $entries = [];

        for ($index = 0; $index + 1 < count($pairs); $index += 2) {
            $subject = $pairs[$index];
            $rights = $pairs[$index + 1];

            if (is_string($subject) && is_string($rights) && trim($subject) !== '') {
                $entries[$subject] = $rights;
            }
        }

        return AclSet::fromStrings($entries);
    }

    /**
     * Does the mailbox exist, by that exact name?
     *
     * `LIST` rather than `SELECT`: selecting opens the mailbox, updates its
     * `\Recent` state and marks the operation in the server's own records.
     * Asking a question should not change the answer.
     *
     * A `\Noselect` entry counts as absent. Such a name exists only to hold
     * children and cannot receive mail, so treating it as a mailbox would let a
     * new account be refused because of a node that is not one.
     */
    private function existsByName(string $name): bool
    {
        $result = $this->administrative()->send(
            ImapCommand::of('LIST', ImapArgument::text(''), ImapArgument::mailbox($name)),
        );

        foreach ($this->entriesOf($result) as $entry) {
            if (strcasecmp($entry->name, $name) === 0) {
                return $entry->isSelectable();
            }
        }

        return false;
    }

    /**
     * Is the mailbox there, according to the refusal we just got?
     *
     * Response code first (RFC 5530), because that is a contract. Cyrus 3.10
     * sends none, so the fallback asks the server instead of reading its
     * English — see the class comment for why that trade is the right way round.
     */
    private function isPresent(CommandFailed $failure, string $mailbox): bool
    {
        if ($failure->hasResponseCode('ALREADYEXISTS')) {
            return true;
        }

        if (self::saysMissing($failure)) {
            return false;
        }

        return $failure->responseCode === null && $this->existsByName($mailbox);
    }

    /**
     * The mirror image. Not `!isPresent()`: a refusal carrying a code we do not
     * know means we know neither, and both questions have to answer "no" then.
     */
    private function isAbsent(CommandFailed $failure, string $mailbox): bool
    {
        if (self::saysMissing($failure)) {
            return true;
        }

        if ($failure->hasResponseCode('ALREADYEXISTS')) {
            return false;
        }

        return $failure->responseCode === null && !$this->existsByName($mailbox);
    }

    private static function saysMissing(CommandFailed $failure): bool
    {
        return $failure->hasResponseCode('NONEXISTENT')
            || $failure->hasResponseCode('TRYCREATE');
    }

    /**
     * Is this an account's own mailbox rather than a folder inside one?
     *
     * `user/anna` and `user/anna@example.test` are; `user/anna/Sent` is not.
     * Only the former is subject to `allowusermoves`.
     */
    private static function isUserMailbox(string $name): bool
    {
        if (!str_starts_with($name, 'user' . self::SEPARATOR)) {
            return false;
        }

        $rest = substr($name, strlen('user' . self::SEPARATOR));

        return !str_contains($rest, self::SEPARATOR);
    }
}

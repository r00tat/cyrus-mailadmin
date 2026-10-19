<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Mailadmin\Domain\Mailstore\Acl;
use Mailadmin\Domain\Mailstore\AclSet;
use Mailadmin\Domain\Mailstore\MailboxEntry;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * A mail store that lives in an array. The reason {@see Mailstore} exists.
 *
 * Every use case is tested against this rather than against Cyrus, so it has to
 * be **as strict as the real thing, not more forgiving**. A double that quietly
 * allows what the server refuses turns a green suite into a promise nobody
 * keeps. The rules it therefore enforces:
 *
 *  - creating a mailbox that exists fails, with the same error code;
 *  - a quota or an ACL on a mailbox that does not exist fails;
 *  - a rename onto an occupied name fails, and takes the folders with it when
 *    it succeeds;
 *  - deleting removes the folders below the mailbox as well.
 *
 * It also does the one thing a fake can do and a server cannot: it **records
 * what was asked of it**, so that a test can assert on the *order* of operations
 * — which is what architecture 8.1 is about, and the part a state assertion
 * alone cannot see.
 *
 * This class ships in `src/` rather than in `tests/` on purpose. It is named in
 * M2's artefact list, it is the reference implementation of the port's
 * semantics, and the contract test set runs against it and the Cyrus adapter
 * alike. It is never wired into the container outside `MAILADMIN_ENV=test`.
 */
final class InMemoryMailstore implements Mailstore
{
    /** @var array<string, true> mailbox name (server form) => exists */
    private array $mailboxes = [];

    /** @var array<string, Quota> */
    private array $quotas = [];

    /** @var array<string, AclSet> */
    private array $acls = [];

    /** @var array<string, true> subscribed folder names */
    private array $subscriptions = [];

    /** @var list<string> */
    private array $operations = [];

    /**
     * Usage the store reports for a mailbox, in KiB. Nothing here counts
     * messages, so a test that cares about a quota being exceeded says so.
     *
     * @var array<string, int>
     */
    private array $usage = [];

    public function createMailbox(MailboxIdentity $identity): void
    {
        $name = $identity->imapMailbox();
        $this->record('create', $name);

        if (isset($this->mailboxes[$name])) {
            throw MailboxOperationFailed::alreadyExists($name);
        }

        $this->mailboxes[$name] = true;
    }

    public function deleteMailbox(MailboxIdentity $identity): void
    {
        $name = $identity->imapMailbox();
        $this->record('delete', $name);

        foreach ($this->namesBelow($name) as $child) {
            unset(
                $this->mailboxes[$child],
                $this->quotas[$child],
                $this->acls[$child],
                $this->subscriptions[$child],
                $this->usage[$child],
            );
        }
    }

    public function renameMailbox(MailboxIdentity $from, MailboxIdentity $to): void
    {
        $source = $from->imapMailbox();
        $target = $to->imapMailbox();
        $this->record('rename', $source . ' -> ' . $target);

        if (!isset($this->mailboxes[$source])) {
            throw MailboxOperationFailed::notFound($source);
        }

        if (isset($this->mailboxes[$target])) {
            throw MailboxOperationFailed::alreadyExists($target);
        }

        [$sourceHead] = self::split($source);
        [$targetHead, $targetTail] = self::split($target);

        // The folders move with the mailbox. A rename that left them behind
        // would pass a test that only looks at the INBOX and lose a user's Sent
        // folder in production.
        foreach ($this->namesBelow($source) as $child) {
            [$childHead] = self::split($child);
            $moved = $targetHead . substr($childHead, strlen($sourceHead)) . $targetTail;

            $this->moveKey($this->mailboxes, $child, $moved);
            $this->moveKey($this->quotas, $child, $moved);
            $this->moveKey($this->acls, $child, $moved);
            $this->moveKey($this->subscriptions, $child, $moved);
            $this->moveKey($this->usage, $child, $moved);
        }
    }

    public function mailboxExists(MailboxIdentity $identity): bool
    {
        return isset($this->mailboxes[$identity->imapMailbox()]);
    }

    /**
     * @return list<MailboxEntry>
     */
    public function listMailboxes(string $pattern): iterable
    {
        $expression = self::patternToRegex($pattern);
        $names = array_keys($this->mailboxes);
        sort($names);

        $entries = [];

        foreach ($names as $name) {
            if (preg_match($expression, $name) !== 1) {
                continue;
            }

            $entries[] = new MailboxEntry(
                $name,
                $this->namesBelow($name) === [$name] ? [] : [MailboxEntry::HAS_CHILDREN],
            );
        }

        return $entries;
    }

    public function getQuota(MailboxIdentity $identity): Quota
    {
        $name = $this->guardExists($identity);
        $quota = $this->quotas[$name] ?? Quota::unlimited();
        $used = $this->usage[$name] ?? null;

        return $used === null ? $quota : $quota->withUsage($used);
    }

    public function setQuota(MailboxIdentity $identity, Quota $quota): void
    {
        $name = $this->guardExists($identity);
        $this->record('setQuota', $name);

        if ($quota->isUnlimited()) {
            unset($this->quotas[$name]);

            return;
        }

        $this->quotas[$name] = $quota;
    }

    public function getAcl(MailboxIdentity $identity): AclSet
    {
        return $this->acls[$this->guardExists($identity)] ?? AclSet::empty();
    }

    public function setAcl(MailboxIdentity $identity, string $subject, Acl $acl): void
    {
        $name = $this->guardExists($identity);
        $this->record('setAcl', $name . ' ' . $subject);

        $current = $this->acls[$name] ?? AclSet::empty();

        $this->acls[$name] = $acl->isEmpty()
            ? $current->without($subject)
            : $current->with($subject, $acl);
    }

    public function createDefaultFolders(MailboxIdentity $identity, DefaultFolders $folders): void
    {
        $this->guardExists($identity);

        foreach ($folders->names() as $folder) {
            $name = $identity->imapFolder($folder);
            $this->record('createFolder', $name);

            $this->mailboxes[$name] = true;
            $this->subscriptions[$name] = true;
        }
    }

    // -----------------------------------------------------------------
    // What only a double can offer
    // -----------------------------------------------------------------

    /**
     * Every operation in the order it was asked for, as `verb name`.
     *
     * Architecture 8.1 is a statement about *order* — mailbox before row on
     * create, row before mailbox on delete. A test that only looks at the end
     * state cannot tell a correct implementation from one that got lucky.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function forget(): void
    {
        $this->operations = [];
    }

    public function isSubscribed(MailboxIdentity $identity, string $folder): bool
    {
        return isset($this->subscriptions[$identity->imapFolder($folder)]);
    }

    /**
     * Puts a mailbox there without going through {@see createMailbox()} — for
     * arranging the state a test starts from, including the states the API
     * refuses to produce.
     */
    public function given(MailboxIdentity $identity, ?int $usedKibibytes = null): void
    {
        $name = $identity->imapMailbox();
        $this->mailboxes[$name] = true;

        if ($usedKibibytes !== null) {
            $this->usage[$name] = $usedKibibytes;
        }
    }

    /**
     * A mailbox the server knows about and no account claims — the orphan A6
     * reconciles and U-22 insists on showing.
     */
    public function givenOrphan(string $serverName): void
    {
        $this->mailboxes[$serverName] = true;
    }

    // -----------------------------------------------------------------

    /**
     * @throws MailboxOperationFailed
     */
    private function guardExists(MailboxIdentity $identity): string
    {
        $name = $identity->imapMailbox();

        if (!isset($this->mailboxes[$name])) {
            throw MailboxOperationFailed::notFound($name);
        }

        return $name;
    }

    /**
     * The mailbox and everything below it.
     *
     * Two traps here, both of which a plain `str_starts_with` walks into:
     *
     *  - `user/anna` must not match `user/annabel`. The separator has to be part
     *    of the comparison, or a delete takes a stranger's mailbox with it.
     *  - **Under `fqun` a folder is not a string prefix of its own mailbox.**
     *    The INBOX is `user/anna@example.test` and its Sent folder is
     *    `user/anna/Sent@example.test` — the domain stays at the end of the
     *    whole name (architecture 3.3). Comparing the raw strings finds no
     *    children at all, so a rename would move the INBOX and orphan every
     *    folder under it.
     *
     * @return list<string>
     */
    private function namesBelow(string $name): array
    {
        [$head, $tail] = self::split($name);
        $below = [];

        foreach (array_keys($this->mailboxes) as $candidate) {
            if ($candidate === $name) {
                $below[] = $candidate;

                continue;
            }

            [$candidateHead, $candidateTail] = self::split($candidate);

            if ($candidateTail === $tail && str_starts_with($candidateHead, $head . '/')) {
                $below[] = $candidate;
            }
        }

        sort($below);

        return $below;
    }

    /**
     * A server mailbox name split into its hierarchy part and its `@domain`
     * suffix, which is empty under `dotted`.
     *
     * Splitting at the **last** `@` is the one decomposition `CLAUDE.md` allows,
     * and it is unambiguous for the same reason it is there: an `@` in a Cyrus
     * mailbox name can only be the domain separator.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $name): array
    {
        $at = strrpos($name, '@');

        return $at === false
            ? [$name, '']
            : [substr($name, 0, $at), substr($name, $at)];
    }

    /**
     * @param array<string, mixed> $store
     */
    private function moveKey(array &$store, string $from, string $to): void
    {
        if (!array_key_exists($from, $store)) {
            return;
        }

        $store[$to] = $store[$from];
        unset($store[$from]);
    }

    private function record(string $verb, string $subject): void
    {
        $this->operations[] = $verb . ' ' . $subject;
    }

    /**
     * IMAP's two wildcards: `%` stops at the separator, `*` does not.
     *
     * Delimited with `#`, because the expression the `%` expands to contains the
     * separator itself.
     */
    private static function patternToRegex(string $pattern): string
    {
        $expression = '';

        foreach (str_split($pattern) as $character) {
            $expression .= match ($character) {
                '*' => '.*',
                '%' => '[^/]*',
                default => preg_quote($character, '#'),
            };
        }

        return '#^' . $expression . '$#';
    }
}

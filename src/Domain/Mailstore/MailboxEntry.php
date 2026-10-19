<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Mailstore;

/**
 * One row of a mailbox listing.
 *
 * The name is the *server's* name, in UTF-8 — `user/anna.example.test/Sent`,
 * or `user/anna/Sent@example.test` under `fqun`. It is deliberately **not** a
 * {@see \Mailadmin\Domain\Model\MailboxIdentity}: turning it into one would mean
 * deciding where the local part ends, and that information is only in
 * `accountuser.domain_name` (architecture 3.3). A listing is the one place that
 * does not have it — which is precisely why A6's reconciliation exists, and why
 * a mailbox with no matching account has to remain something we can *name*
 * without being able to interpret.
 *
 * The flags come from `LIST`. `\Noselect` marks a name that only exists to hold
 * children, and counting one as a mailbox is how a reconciliation report grows
 * entries nobody created.
 */
final readonly class MailboxEntry
{
    public const string NOSELECT = '\Noselect';
    public const string NOINFERIORS = '\Noinferiors';
    public const string HAS_CHILDREN = '\HasChildren';

    /**
     * @param list<string> $flags as announced, backslash included
     */
    public function __construct(
        public string $name,
        public array $flags = [],
        public string $separator = '/',
    ) {
    }

    public function hasFlag(string $flag): bool
    {
        foreach ($this->flags as $candidate) {
            if (strcasecmp($candidate, $flag) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Can this name hold messages, or is it only a node in the hierarchy?
     */
    public function isSelectable(): bool
    {
        return !$this->hasFlag(self::NOSELECT);
    }

    /**
     * Is this the top of a user's tree (`user/<something>`), rather than a
     * folder inside it?
     *
     * Answered on the *server's* form of the name, so it holds for both naming
     * schemes: `user/anna.example.test` has one separator after `user`, and so
     * does `user/anna@example.test`.
     */
    public function isUserRoot(): bool
    {
        if (!str_starts_with($this->name, 'user' . $this->separator)) {
            return false;
        }

        return !str_contains(substr($this->name, strlen('user' . $this->separator)), $this->separator);
    }
}

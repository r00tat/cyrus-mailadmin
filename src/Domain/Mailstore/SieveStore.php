<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Mailstore;

use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Sieve\SieveScript;

/**
 * Sieve scripts, as the application needs them (architecture 9.1).
 *
 * Small on purpose. The application never *edits* a script — architecture 9.4
 * is explicit that a rule set is rendered whole and written whole, because the
 * legacy system read a script back and spliced changes into it with regular
 * expressions, and that was a reliable source of subtle breakage.
 *
 * So there is a `putScript` and there is no `patchScript`, and
 * {@see activeScript()} exists for exactly two reasons: to show the user what is
 * there, and to find out whether it is ours. A script without the mailadmin
 * marker is never overwritten without consent — see {@see SieveScript::isForeign()}.
 *
 * Every method may throw a {@see MailstoreFailure}.
 */
interface SieveStore
{
    /**
     * The script the server currently runs, or `null` when none is active.
     */
    public function activeScript(MailboxIdentity $identity): ?SieveScript;

    /**
     * The stored script of this name, active or not, or `null` when there is
     * none.
     *
     * Not in architecture 9.1, and additive: {@see putScript()} replaces a
     * script of the same name, so whoever is about to store one must be able
     * to find out whether the name already holds a script that is not ours —
     * an inactive one included (U-113). Read to decide, never to edit.
     */
    public function script(MailboxIdentity $identity, string $name): ?SieveScript;

    /**
     * Stores a script under its own name, replacing any script of that name.
     *
     * Storing does not activate. The two are separate because a server that
     * rejects a script must reject it *before* it becomes the one filtering
     * incoming mail.
     */
    public function putScript(MailboxIdentity $identity, SieveScript $script): void;

    public function activate(MailboxIdentity $identity, string $name): void;

    /**
     * Leaves the account with no active script at all.
     *
     * Not in architecture 9.1, and additive for the same kind of reason the two
     * deviations in U-37 are: without it a rule set cannot be **withdrawn**.
     * Every ManageSieve server refuses to delete the script it is running, so
     * "switch the out-of-office off" would have exactly one implementation —
     * store an empty script and leave it active — and an empty active script is
     * indistinguishable, to the next reader, from a script that failed to
     * render.
     *
     * Deactivating an account that has no active script is a success: the
     * postcondition holds, and a repair run (A6) must be able to finish rather
     * than trip over what an earlier one already did.
     */
    public function deactivate(MailboxIdentity $identity): void;

    public function deleteScript(MailboxIdentity $identity, string $name): void;

    /**
     * The names of every script stored for this account.
     *
     * Not in architecture 9.1. A6's reconciliation needs it to report the
     * legacy `sieve` scripts webcyradm left behind, and an interface that cannot
     * enumerate is an interface whose leftovers stay invisible — the failure
     * mode E-18 and U-22 are both about.
     *
     * @return list<string>
     */
    public function scriptNames(MailboxIdentity $identity): array;
}

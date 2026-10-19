<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Mailadmin\Domain\Exception\InvalidSieveRule;
use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Domain\Sieve\SieveRuleSet;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveCapabilities;

/**
 * The one place a rule set becomes the account's active script — and the one
 * place that refuses to overwrite a script we did not write.
 *
 * Architecture 9.4 states two rules. `SieveRenderer` implements the first
 * (generate, never patch) and `SieveScript::isManaged()` recognises the second.
 * **Neither of them enforces it**, and a rule that is merely recognisable is one
 * that every future caller has to remember. This class is where it becomes
 * unavoidable: A3 asks for a rule set to be installed and cannot, by the shape
 * of the method, install it over somebody's hand-written script by accident.
 *
 * It sits above the port rather than inside an adapter, which is what makes the
 * refusal testable against **both** implementations — the double and the real
 * timsieved run the same cases. An adapter-side guard would leave the double
 * more permissive than the server, and every use case in the application is
 * tested against the double.
 *
 * Four steps, in this order, and the order is the point:
 *
 *  1. **Ask what the server can do.** An extension the server does not implement
 *     invalidates the *whole* script, taking the forwards down with the vacation
 *     notice. Finding out at `PUTSCRIPT` time is late and the message is worse.
 *  2. **Ask what is there.** A foreign active script stops the operation with
 *     {@see SieveOperationFailed::foreignScript()} — a distinct code, because the
 *     answer is a question for a person and not a fix for a developer. So does
 *     a foreign script stored, inactive, under the name we would store under
 *     (U-113): storing replaces it, withdrawing deletes it.
 *  3. **Render whole, store whole.** Nothing is read in order to be changed.
 *  4. **Activate afterwards.** Storing and activating are separate so that a
 *     script the server rejects is rejected *before* it becomes the one filtering
 *     incoming mail.
 *
 * A takeover is possible and explicit: `$takeOverForeignScript` is what the
 * user's confirmation looks like by the time it gets here. It is a parameter and
 * not a configuration flag, so that consent belongs to one operation rather than
 * to an installation.
 */
final readonly class SieveScriptInstaller
{
    public function __construct(
        private SieveStore $scripts,
        private SieveRenderer $renderer,
        private ?SieveCapabilities $capabilities = null,
    ) {
    }

    /**
     * Makes `$rules` the account's active script, or removes the script when the
     * rule set is empty.
     *
     * @return SieveScript|null the script now active, or `null` when there is
     *                          nothing left to run
     *
     * @throws SieveOperationFailed when the active script is foreign and no
     *                              takeover was given, or when the server lacks
     *                              an extension the rule set needs
     * @throws InvalidSieveRule     when the rule set cannot be rendered — a
     *                              domain fault, not a server one
     */
    public function install(
        MailboxIdentity $identity,
        SieveRuleSet $rules,
        bool $takeOverForeignScript = false,
    ): ?SieveScript {
        $this->guardExtensions($identity, $rules);
        $this->guardActiveScript($identity, $takeOverForeignScript);
        $this->guardStoredScript($identity, $takeOverForeignScript);

        if ($rules->isEmpty()) {
            $this->withdraw($identity);

            return null;
        }

        // The legacy name, so that a script webcyradm left behind is the same
        // one we replace rather than a second script beside it — M3's open
        // point 1. The marker, not the name, is what tells the two apart.
        $script = $this->renderer->render($rules, SieveScript::LEGACY_NAME);

        $this->scripts->putScript($identity, $script);
        $this->scripts->activate($identity, $script->name);

        return $script;
    }

    /**
     * Withdraws everything mailadmin installed: nothing active, and our script
     * gone.
     *
     * @throws SieveOperationFailed when the active script is foreign and no
     *                              takeover was given
     */
    public function remove(MailboxIdentity $identity, bool $takeOverForeignScript = false): void
    {
        $this->guardActiveScript($identity, $takeOverForeignScript);
        $this->guardStoredScript($identity, $takeOverForeignScript);
        $this->withdraw($identity);
    }

    /**
     * The active script, when it is one we did not write — otherwise `null`.
     *
     * What a read-only view is built from (architecture 9.4). Kept separate from
     * {@see \Mailadmin\Domain\Mailstore\SieveStore::activeScript()} so that a
     * caller asking "is there something in the way" does not have to know how to
     * decide it.
     */
    public function foreignScript(MailboxIdentity $identity): ?SieveScript
    {
        $active = $this->scripts->activeScript($identity);

        return $active !== null && $active->isForeign() ? $active : null;
    }

    // -----------------------------------------------------------------

    /**
     * @throws SieveOperationFailed
     */
    private function guardExtensions(MailboxIdentity $identity, SieveRuleSet $rules): void
    {
        if ($this->capabilities === null) {
            return;
        }

        $available = $this->capabilities->sieveExtensions($identity);

        foreach ($rules->requiredExtensions() as $extension) {
            if (!in_array(strtolower($extension), $available, true)) {
                throw SieveOperationFailed::extensionMissing(
                    $identity->cyrusUsername(),
                    $extension,
                );
            }
        }
    }

    /**
     * @throws SieveOperationFailed
     */
    private function guardActiveScript(MailboxIdentity $identity, bool $takeOver): void
    {
        if ($takeOver) {
            return;
        }

        $active = $this->scripts->activeScript($identity);

        if ($active === null || $active->isManaged()) {
            return;
        }

        throw SieveOperationFailed::foreignScript($identity->cyrusUsername(), $active->name);
    }

    /**
     * The name we store under may already hold a script we did not write —
     * an inactive one: webcyradm's switched-off notice, or one the user keeps
     * in Roundcube. Storing would replace it and withdrawing would delete it,
     * so it is the same question as a foreign *active* script (U-113).
     *
     * @throws SieveOperationFailed
     */
    private function guardStoredScript(MailboxIdentity $identity, bool $takeOver): void
    {
        if ($takeOver) {
            return;
        }

        $stored = $this->scripts->script($identity, SieveScript::LEGACY_NAME);

        if ($stored === null || $stored->isManaged()) {
            return;
        }

        throw SieveOperationFailed::foreignStoredScript($identity->cyrusUsername(), $stored->name);
    }

    /**
     * Deactivate, then delete — in that order, because every ManageSieve server
     * refuses to delete the script it is running.
     */
    private function withdraw(MailboxIdentity $identity): void
    {
        $this->scripts->deactivate($identity);
        $this->scripts->deleteScript($identity, SieveScript::LEGACY_NAME);
    }
}

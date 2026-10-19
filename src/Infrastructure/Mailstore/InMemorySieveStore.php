<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Sieve\SieveScript;

/**
 * Sieve scripts in an array — the counterpart to {@see InMemoryMailstore}, and
 * what the vacation and forwarding use cases are tested against.
 *
 * The rule it exists to make testable is architecture 9.4's: a script is
 * rendered whole and written whole, never read back and patched. So this class
 * stores exactly what it was given and hands back exactly that — no merging, no
 * normalising. A double that tidied the content on the way in would hide the one
 * defect worth catching, which is a renderer that produces something the server
 * would refuse.
 *
 * It also keeps the second rule honest: {@see activeScript()} is what tells a
 * use case that the active script is *foreign* — written by webcyradm or by
 * hand — and must not be overwritten without consent. Arranging that state is
 * what {@see givenForeignScript()} is for.
 */
final class InMemorySieveStore implements SieveStore
{
    /** @var array<string, array<string, SieveScript>> account => name => script */
    private array $scripts = [];

    /** @var array<string, string> account => the active script's name */
    private array $active = [];

    /** @var list<string> */
    private array $operations = [];

    public function activeScript(MailboxIdentity $identity): ?SieveScript
    {
        $account = $identity->cyrusUsername();
        $name = $this->active[$account] ?? null;

        return $name === null ? null : ($this->scripts[$account][$name] ?? null);
    }

    public function script(MailboxIdentity $identity, string $name): ?SieveScript
    {
        return $this->scripts[$identity->cyrusUsername()][$name] ?? null;
    }

    public function putScript(MailboxIdentity $identity, SieveScript $script): void
    {
        $account = $identity->cyrusUsername();
        $this->operations[] = 'put ' . $account . ' ' . $script->name;

        $this->scripts[$account][$script->name] = $script;
    }

    public function activate(MailboxIdentity $identity, string $name): void
    {
        $account = $identity->cyrusUsername();
        $this->operations[] = 'activate ' . $account . ' ' . $name;

        if (!isset($this->scripts[$account][$name])) {
            throw SieveOperationFailed::scriptNotFound($account, $name);
        }

        $this->active[$account] = $name;
    }

    public function deactivate(MailboxIdentity $identity): void
    {
        $account = $identity->cyrusUsername();
        $this->operations[] = 'deactivate ' . $account;

        // `SETACTIVE ""` on a server with nothing active answers OK, so the
        // double answers the same way. A double that was stricter than the
        // server would fail a use case that the server would have accepted.
        unset($this->active[$account]);
    }

    public function deleteScript(MailboxIdentity $identity, string $name): void
    {
        $account = $identity->cyrusUsername();
        $this->operations[] = 'delete ' . $account . ' ' . $name;

        // Deleting the active script is refused by every ManageSieve server:
        // it would leave the account filtering with something that is gone.
        if (($this->active[$account] ?? null) === $name) {
            throw SieveOperationFailed::activeScriptCannotBeDeleted($account, $name);
        }

        unset($this->scripts[$account][$name]);
    }

    /**
     * @return list<string>
     */
    public function scriptNames(MailboxIdentity $identity): array
    {
        $names = array_keys($this->scripts[$identity->cyrusUsername()] ?? []);
        sort($names);

        return $names;
    }

    // -----------------------------------------------------------------
    // What only a double can offer
    // -----------------------------------------------------------------

    /**
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

    /**
     * An active script that is not ours — the state architecture 9.4 says must
     * never be overwritten silently. Usually a leftover from webcyradm, which
     * called its script `sieve`.
     */
    public function givenStoredScript(MailboxIdentity $identity, SieveScript $script): void
    {
        $this->scripts[$identity->cyrusUsername()][$script->name] = $script;
    }

    public function givenForeignScript(
        MailboxIdentity $identity,
        string $content,
        string $name = SieveScript::LEGACY_NAME,
    ): void {
        $account = $identity->cyrusUsername();

        $this->scripts[$account][$name] = SieveScript::of($name, $content);
        $this->active[$account] = $name;
    }
}

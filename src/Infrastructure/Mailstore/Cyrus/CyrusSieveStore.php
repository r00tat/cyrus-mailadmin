<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Cyrus;

use Mailadmin\Domain\Exception\InvalidSieveRule;
use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnection;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveCapabilities;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveLine;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveResponse;
use Mailadmin\Infrastructure\Mailstore\SieveOperationFailed;

/**
 * {@see SieveStore} against a real timsieved, over M3's ManageSieve client.
 *
 * The sibling of {@see CyrusMailstore}, and it repeats three of that adapter's
 * decisions because they were right there for reasons that apply here too.
 *
 * **1. Every session acts for the user, never for the administrator.**
 * A Sieve script lives in the space of whoever the session is authorised as, so
 * the administrator's own session would store the user's out-of-office notice
 * against the administrator's account — and every command would answer `OK`
 * while doing it. The connection therefore always comes from
 * {@see ManageSieveConnectionPool::actingFor()}, which is architecture 9.3's
 * proxy authorisation and needs no user password.
 *
 * **2. A refusal is read from its response code, and when there is none the
 * server is asked a second question.** RFC 5804 defines `NONEXISTENT` and
 * `ACTIVE`; timsieved does not always send them, and M2 found the same gap over
 * IMAP (U-38 d). So `DELETESCRIPT` that fails without a code is followed by a
 * `LISTSCRIPTS` that establishes the fact, rather than by a guess at the
 * server's English. One extra round trip, on the error path only.
 *
 * **3. Server prose is capped before it becomes a message.** A `NO` after a
 * rejected `PUTSCRIPT` quotes the offending line of the script — and a script
 * holds a vacation text, which is personal data with no business in a log. Enough
 * survives to name the syntax error; the whole of it does not. See
 * {@see SieveResponse::operatorText()}.
 *
 * **What this class does not do is read a script in order to change it.** There
 * is a `putScript` and no `patchScript`, and the only thing ever *read* back is
 * the content the caller asked for — to show it, and to let
 * {@see SieveScript::isManaged()} decide whether it is ours. Architecture 9.4 is
 * the whole reason M3 exists.
 */
final class CyrusSieveStore implements SieveStore, SieveCapabilities
{
    /** `SETACTIVE ""` is RFC 5804's way of saying "run nothing". */
    private const string NOTHING = '';

    public function __construct(private readonly ManageSieveConnectionPool $pool)
    {
    }

    public function activeScript(MailboxIdentity $identity): ?SieveScript
    {
        $account = $identity->cyrusUsername();
        $connection = $this->pool->actingFor($account);

        $name = $this->activeName($connection);

        if ($name === null) {
            return null;
        }

        $response = $connection->send('GETSCRIPT', [$name]);

        if (!$response->isOk()) {
            if ($response->hasCode('NONEXISTENT')) {
                // Listed a moment ago and gone now. Rare, and honest to report as
                // "nothing active" rather than as a fault.
                return null;
            }

            throw SieveOperationFailed::rejected($account, $name, $response->operatorText());
        }

        return self::scriptOf($account, $name, self::contentOf($response));
    }

    public function script(MailboxIdentity $identity, string $name): ?SieveScript
    {
        $account = $identity->cyrusUsername();
        $connection = $this->pool->actingFor($account);
        $response = $connection->send('GETSCRIPT', [$name]);

        if (!$response->isOk()) {
            if ($response->hasCode('NONEXISTENT') || !$this->exists($connection, $name)) {
                return null;
            }

            throw SieveOperationFailed::rejected($account, $name, $response->operatorText());
        }

        return self::scriptOf($account, $name, self::contentOf($response));
    }

    public function putScript(MailboxIdentity $identity, SieveScript $script): void
    {
        $account = $identity->cyrusUsername();
        $connection = $this->pool->actingFor($account);

        // Name first, content second — and the content always travels as a
        // literal, counted in **octets**. That is what makes M3's fourth open
        // point a non-question: a vacation text with umlauts, quotation marks and
        // newlines is written and read as the same bytes, and no `iconv` sits
        // anywhere on the path. The legacy system converted, and converting is
        // how an umlaut becomes a question mark in somebody's auto-reply.
        $response = $connection->send('PUTSCRIPT', [$script->name, $script->content]);

        if ($response->isOk()) {
            return;
        }

        throw SieveOperationFailed::rejected($account, $script->name, $response->operatorText());
    }

    public function activate(MailboxIdentity $identity, string $name): void
    {
        $account = $identity->cyrusUsername();
        $connection = $this->pool->actingFor($account);
        $response = $connection->send('SETACTIVE', [$name]);

        if ($response->isOk()) {
            return;
        }

        if ($response->hasCode('NONEXISTENT') || !$this->exists($connection, $name)) {
            throw SieveOperationFailed::scriptNotFound($account, $name);
        }

        throw SieveOperationFailed::rejected($account, $name, $response->operatorText());
    }

    public function deactivate(MailboxIdentity $identity): void
    {
        $account = $identity->cyrusUsername();
        $response = $this->pool->actingFor($account)->send('SETACTIVE', [self::NOTHING]);

        if ($response->isOk()) {
            return;
        }

        throw SieveOperationFailed::rejected($account, self::NOTHING, $response->operatorText());
    }

    public function deleteScript(MailboxIdentity $identity, string $name): void
    {
        $account = $identity->cyrusUsername();
        $connection = $this->pool->actingFor($account);
        $response = $connection->send('DELETESCRIPT', [$name]);

        if ($response->isOk()) {
            return;
        }

        if ($response->hasCode('ACTIVE')) {
            throw SieveOperationFailed::activeScriptCannotBeDeleted($account, $name);
        }

        if ($response->hasCode('NONEXISTENT')) {
            return;
        }

        // No response code — the case M2 met over IMAP. Ask instead of reading
        // the server's sentence: the two facts that matter are "is it the active
        // one" and "is it there at all", and both are questions LISTSCRIPTS
        // answers.
        $active = $this->activeName($connection);

        if ($active === $name) {
            throw SieveOperationFailed::activeScriptCannotBeDeleted($account, $name);
        }

        if (!$this->exists($connection, $name)) {
            // Deleting what is not there is a success: the postcondition holds,
            // and a repair run has to be able to repeat itself.
            return;
        }

        throw SieveOperationFailed::rejected($account, $name, $response->operatorText());
    }

    /**
     * @return list<string>
     */
    public function scriptNames(MailboxIdentity $identity): array
    {
        $names = $this->listScripts($this->pool->actingFor($identity->cyrusUsername()));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    public function sieveExtensions(MailboxIdentity $identity): array
    {
        return $this->pool->actingFor($identity->cyrusUsername())->sieveExtensions();
    }

    // -----------------------------------------------------------------

    /**
     * The name of the script the server is running, or `null`.
     *
     * `LISTSCRIPTS` marks it with the bare atom `ACTIVE`. A script *called*
     * `ACTIVE` arrives as a quoted string and is therefore a different token —
     * which is why {@see SieveLine} keeps the two kinds apart instead of
     * splitting on whitespace.
     */
    private function activeName(ManageSieveConnection $connection): ?string
    {
        foreach ($connection->execute('LISTSCRIPTS')->lines as $line) {
            $name = $line->strings()[0] ?? null;

            if ($name !== null && $line->hasAtom('ACTIVE')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function listScripts(ManageSieveConnection $connection): array
    {
        $names = [];

        foreach ($connection->execute('LISTSCRIPTS')->lines as $line) {
            $name = $line->strings()[0] ?? null;

            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function exists(ManageSieveConnection $connection, string $name): bool
    {
        return in_array($name, $this->listScripts($connection), true);
    }

    /**
     * The script content out of a `GETSCRIPT` answer.
     *
     * The content is the whole of what precedes the `OK` — one string, whether
     * the server sent it as a literal (always, for anything with a newline in
     * it) or quoted.
     */
    private static function contentOf(SieveResponse $response): string
    {
        foreach ($response->lines as $line) {
            $content = $line->strings()[0] ?? null;

            if ($content !== null) {
                return $content;
            }
        }

        return '';
    }

    /**
     * A script name the server chose, which our own value object may refuse.
     *
     * ManageSieve allows a name this application cannot address — one holding a
     * newline, say. Letting {@see InvalidSieveRule} out of a mailstore method
     * would hand the caller an exception it does not catch; turning it into a
     * refusal keeps the promise the port makes, which is that everything here
     * fails as a {@see \Mailadmin\Domain\Mailstore\MailstoreFailure}.
     */
    private static function scriptOf(string $account, string $name, string $content): SieveScript
    {
        try {
            return SieveScript::of($name, $content);
        } catch (InvalidSieveRule $invalid) {
            throw SieveOperationFailed::rejected(
                $account,
                $name,
                'the server returned a script name this client cannot address',
                $invalid,
            );
        }
    }
}

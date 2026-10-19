<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Sieve;

use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * What the Sieve server can do — asked before a script is written, not after.
 *
 * M3's open point 2, answered: a script that requires an extension the server
 * does not implement is rejected **in full**. The vacation notice, the forwards
 * and the filters go down together, and the only thing the user sees is that
 * saving failed. Asking first turns that into a specific message naming the
 * extension, which is the difference between a server that has to be configured
 * and a bug that has to be found.
 *
 * **Not part of {@see \Mailadmin\Domain\Mailstore\SieveStore}.** The port is the
 * domain's (U-37) and describes storing scripts; server capabilities are an
 * infrastructure fact and an optional one — the in-memory double has none and
 * needs none. So this is a separate, small interface that
 * {@see \Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller} takes as a
 * nullable collaborator.
 *
 * **It takes an identity** although capabilities are a property of the server.
 * That is deliberate: the implementation then answers from the very session the
 * script will be written on. A Cyrus murder can refer a session elsewhere, and a
 * capability list read from a different connection would be an answer about a
 * different server.
 */
interface SieveCapabilities
{
    /**
     * The Sieve extensions the server announces for this account, lower case
     * and sorted — comparable with
     * {@see \Mailadmin\Domain\Sieve\SieveRuleSet::requiredExtensions()}.
     *
     * @return list<string>
     */
    public function sieveExtensions(MailboxIdentity $identity): array;
}

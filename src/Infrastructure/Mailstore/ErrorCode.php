<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

/**
 * The stable error codes the mailstore layer raises (U-7).
 *
 * Two prefixes, both enumerated in U-7: `mailstore` for everything that speaks
 * IMAP, `sieve` for everything that speaks ManageSieve. A caller reacts to the
 * code, never to the message — the message is written for an operator's log and
 * is never handed to a client (`CLAUDE.md`, "Fehler").
 *
 * Which of these reach the HTTP layer, and as what status, is a decision for the
 * A packages; the codes have to be listed in `openapi/components/common.yaml`
 * before a handler may answer with one. That file belongs to F2, so M2 and M3
 * ask for the entries rather than adding them.
 */
final class ErrorCode
{
    // ---------------------------------------------------------------------
    // Transport (M1)
    // ---------------------------------------------------------------------

    /** No usable connection: DNS, TCP, TLS handshake or certificate. */
    public const string UNAVAILABLE = 'mailstore.unavailable';

    /** The server's certificate did not verify. Never downgraded to a warning. */
    public const string TLS_REJECTED = 'mailstore.tls_rejected';

    /** The administrator could not authenticate, or proxy auth was refused. */
    public const string AUTHENTICATION_FAILED = 'mailstore.authentication_failed';

    /** Connect or read exceeded the configured timeout. */
    public const string TIMEOUT = 'mailstore.timeout';

    /** The server answered `NO` or `BAD`. */
    public const string COMMAND_FAILED = 'mailstore.command_failed';

    /** The answer did not parse: truncated literal, stray token, wrong tag. */
    public const string PROTOCOL_ERROR = 'mailstore.protocol_error';

    /** The configuration is incomplete or contradicts a mandatory rule. */
    public const string CONFIGURATION_INVALID = 'mailstore.configuration_invalid';

    // ---------------------------------------------------------------------
    // Mailboxes (M2)
    // ---------------------------------------------------------------------

    /** The mailbox the operation names does not exist. */
    public const string MAILBOX_NOT_FOUND = 'mailstore.mailbox_not_found';

    /** A rename or create would land on a mailbox that is already there. */
    public const string MAILBOX_EXISTS = 'mailstore.mailbox_exists';

    /** The server refuses the hierarchy separator we depend on. */
    public const string UNSUPPORTED_NAMESPACE = 'mailstore.unsupported_namespace';

    /** The quota could not be set or read. */
    public const string QUOTA_REJECTED = 'mailstore.quota_rejected';

    /** The ACL could not be set or read. */
    public const string ACL_REJECTED = 'mailstore.acl_rejected';

    // ---------------------------------------------------------------------
    // Sieve (M3)
    // ---------------------------------------------------------------------

    /** ManageSieve is not reachable or the greeting was unusable. */
    public const string SIEVE_UNAVAILABLE = 'sieve.unavailable';

    /** The server rejected the script — syntax, size or an unknown extension. */
    public const string SIEVE_REJECTED = 'sieve.rejected';

    /** The script the rule set needs an extension the server does not announce. */
    public const string SIEVE_EXTENSION_MISSING = 'sieve.extension_missing';

    /** The active script carries no mailadmin marker; overwriting needs consent. */
    public const string SIEVE_SCRIPT_FOREIGN = 'sieve.script_foreign';

    /** The named script is not on the server. */
    public const string SIEVE_SCRIPT_NOT_FOUND = 'sieve.script_not_found';

    private function __construct()
    {
    }
}

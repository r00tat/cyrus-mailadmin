<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Sieve;

use Mailadmin\Infrastructure\Mailstore\AuthenticationFailed;
use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\TransportFactory;

/**
 * ManageSieve sessions, reused within one request — the sibling of
 * {@see \Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool}.
 *
 * Separate from the IMAP pool rather than shared with it, and not for tidiness:
 * the two speak to **different ports** (4190 against 993) with different
 * protocols. One pool would have to key by endpoint as well as by identity, and
 * would then hand an IMAP client a ManageSieve socket the first time somebody
 * got the key wrong.
 *
 * **Keyed by authorisation identity.** A session authenticated with
 * `authzid = anna.example.test` acts as that user for the rest of its life —
 * SASL offers no way back out — so proxied sessions are separate entries and the
 * administrator's own is the entry under a key no user name can produce. In
 * ManageSieve this matters more than in IMAP: a script is stored *in the space
 * of whoever the session acts for*, so a reused wrong session does not fail, it
 * writes the right script into the wrong account.
 *
 * **TLS is always negotiated.** RFC 5804 has no implicit-TLS port and Cyrus's
 * timsieved offers none, so the session starts in the clear and is upgraded
 * before anything is written that must not be seen. The upgrade is not
 * conditional on configuration: if the transport is not encrypted, `STARTTLS`
 * runs, and if it cannot, the connection fails rather than continuing.
 *
 * **The lifetime is the request**, exactly as for M1: the container lives for
 * one request and {@see closeAll()} ends the sessions.
 */
final class ManageSieveConnectionPool
{
    /** The key of the administrator's own, unproxied session. */
    private const string ADMINISTRATOR = "\0self";

    /** @var array<string, ManageSieveConnection> */
    private array $connections = [];

    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly Credentials $credentials,
        private readonly TransportFactory $transports,
        private readonly SieveTrace $trace,
    ) {
    }

    /**
     * @throws ConnectionFailed
     * @throws AuthenticationFailed
     */
    public function administrative(): ManageSieveConnection
    {
        return $this->connection(self::ADMINISTRATOR, null);
    }

    /**
     * Connect, upgrade to TLS with certificate verification, and hang up again
     * without authenticating — the health probe (A6, U-121).
     *
     * Not {@see administrative()}: Cyrus's timsieved refuses the administrator's
     * own, unproxied session (measured against F5: "Authentication Error"), and
     * proxying for some account would make the probe depend on that account.
     * The administrator's credentials are proved by the IMAP probe against the
     * same SASL backend. The session is not kept in the pool.
     *
     * @throws ConnectionFailed
     * @throws MailstoreError
     */
    public function probe(): void
    {
        $connection = ManageSieveConnection::open($this->transports->connect($this->endpoint), $this->trace);

        try {
            $connection->startTls();

            if (!$connection->supportsMechanism('PLAIN')) {
                throw ConnectionFailed::tls(
                    $this->endpoint->toString(),
                    'the server offers no PLAIN mechanism after STARTTLS',
                );
            }

            $connection->logout();
        } finally {
            $connection->close();
        }
    }

    /**
     * A session that acts for `$user` through SASL proxy authorisation
     * (architecture 9.3) — the way to write a user's script without their
     * password.
     *
     * @throws ConnectionFailed
     * @throws AuthenticationFailed
     */
    public function actingFor(string $user): ManageSieveConnection
    {
        $identity = trim($user);

        if ($identity === '') {
            throw AuthenticationFailed::forProxy(
                $this->credentials->administrator,
                $user,
                'the authorisation identity is empty',
            );
        }

        return $this->connection($identity, $identity);
    }

    /**
     * How many sessions are open. For tests, and for the diagnostic that says
     * whether a page is opening one per row.
     */
    public function size(): int
    {
        return count($this->connections);
    }

    public function administratorName(): string
    {
        return $this->credentials->administrator;
    }

    /**
     * `host:port`, for a message. Never carries a credential.
     */
    public function endpoint(): string
    {
        return $this->endpoint->toString();
    }

    /**
     * Ends every session. Safe to call twice, and never throws: it runs where
     * the request is already over.
     */
    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            try {
                $connection->logout();
            } catch (MailstoreError) {
                $connection->close();
            }
        }

        $this->connections = [];
    }

    /**
     * @throws ConnectionFailed
     * @throws AuthenticationFailed
     */
    private function connection(string $key, ?string $target): ManageSieveConnection
    {
        $existing = $this->connections[$key] ?? null;

        if ($existing !== null) {
            if ($existing->isOpen()) {
                return $existing;
            }

            // The server hung up — an idle timeout, a restart, a `BYE`.
            // Reconnecting is right here and only here: nothing has been sent on
            // this session yet, so no operation is repeated by doing it.
            unset($this->connections[$key]);
        }

        $connection = $this->open($target);
        $this->connections[$key] = $connection;

        return $connection;
    }

    /**
     * @throws ConnectionFailed
     * @throws AuthenticationFailed
     */
    private function open(?string $target): ManageSieveConnection
    {
        $transport = $this->transports->connect($this->endpoint);

        try {
            $connection = ManageSieveConnection::open($transport, $this->trace);

            // Unconditional, not driven by `TlsMode`: ManageSieve has no
            // implicit-TLS port, so "already encrypted" can only mean a
            // deployment put a TLS terminator in front of it, and everything
            // else has to be upgraded before a credential is written.
            $connection->startTls();

            if ($target === null) {
                $connection->authenticate($this->credentials);
            } else {
                $connection->authenticateFor($this->credentials, $target);
            }

            return $connection;
        } catch (MailstoreError $error) {
            // A half-open socket left by a failed handshake is a file descriptor
            // that stays until the worker dies.
            $transport->close();

            throw $error;
        }
    }
}

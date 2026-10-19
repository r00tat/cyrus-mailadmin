<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\AuthenticationFailed;
use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\TlsMode;
use Mailadmin\Infrastructure\Mailstore\Net\TransportFactory;

/**
 * Connections, reused within one request (architecture 9.2).
 *
 * The rule this enforces is the one from `CLAUDE.md`: *no connection per list
 * row*. Listing fifty accounts with their quota is fifty `GETQUOTA` commands,
 * and if each of them opened a socket it would also be fifty TCP handshakes,
 * fifty TLS handshakes and fifty SASL exchanges — for a page that should cost
 * one of each. The legacy system connected per operation and it is why its
 * account list was slow enough that people avoided it.
 *
 * **Keyed by authorisation identity, not by "a connection".** A session
 * authenticated with `authzid = anna.example.test` runs *as* that user for the
 * rest of its life; SASL has no way to step back out. So proxied sessions are
 * separate entries, and the administrator's own session is the entry under
 * `null`. Handing back a proxied connection to a caller that wanted
 * administrative rights would produce `NO Permission denied` at a place that
 * cannot explain it.
 *
 * **The lifetime is the request.** The pool is registered per request in the
 * container; {@see closeAll()} is called when it ends. Nothing here caches
 * across requests, because a PHP-FPM worker outlives the request and a
 * connection held open across requests is a connection nobody is watching.
 */
final class ConnectionPool
{
    /** The key of the administrator's own, unproxied session. */
    private const string ADMINISTRATOR = "\0self";

    /** @var array<string, Connection> */
    private array $connections = [];

    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly Credentials $credentials,
        private readonly TransportFactory $transports,
        private readonly ProtocolTrace $trace,
    ) {
    }

    /**
     * A session with the administrator's own rights.
     *
     * @throws ConnectionFailed
     * @throws AuthenticationFailed
     */
    public function administrative(): Connection
    {
        return $this->connection(self::ADMINISTRATOR, null);
    }

    /**
     * A session that acts for `$user` through SASL proxy authorisation
     * (architecture 9.3) — the way to touch a user's mailbox without their
     * password.
     *
     * @throws ConnectionFailed
     * @throws AuthenticationFailed
     */
    public function actingFor(string $user): Connection
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
     * How many sessions are open. For tests and for a diagnostic line — a page
     * that ends with more than a handful is doing something per row.
     */
    public function size(): int
    {
        return count($this->connections);
    }

    /**
     * The administrator's own account name.
     *
     * The adapter needs it for the ACL it grants itself before a `DELETE`:
     * Cyrus does not give an administrator implicit rights on a user's mailbox.
     */
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
     * the request is already over and there is nobody left to tell.
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
    private function connection(string $key, ?string $target): Connection
    {
        $existing = $this->connections[$key] ?? null;

        if ($existing !== null) {
            if ($existing->isOpen()) {
                return $existing;
            }

            // The server hung up — an idle timeout, a restart. Reconnecting is
            // right here and only here: the caller asked for a session, not for
            // a particular socket, and nothing has been sent on this one yet.
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
    private function open(?string $target): Connection
    {
        $transport = $this->transports->connect($this->endpoint);

        try {
            $connection = Connection::open($transport, $this->trace);

            if ($this->endpoint->tlsMode === TlsMode::StartTls) {
                $connection->startTls();
            }

            if ($target === null) {
                $connection->authenticate($this->credentials);
            } else {
                $connection->authenticateFor($this->credentials, $target);
            }

            return $connection;
        } catch (MailstoreError $error) {
            // A half-open socket left behind by a failed handshake is a file
            // descriptor that stays until the worker dies.
            $transport->close();

            throw $error;
        }
    }
}

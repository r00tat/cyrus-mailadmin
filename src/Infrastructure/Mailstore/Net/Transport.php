<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Net;

use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;

/**
 * A line-oriented, TLS-protected byte stream.
 *
 * IMAP and ManageSieve differ in their grammar and agree on everything below it:
 * CRLF-terminated lines, counted literals, TLS either from the first byte or
 * after a `STARTTLS`. One interface for both means the certificate rules, the
 * timeouts and the "no plaintext, ever" rule are implemented once instead of
 * twice — and that the fake used in unit tests is one class, not two.
 *
 * **Every method has a deadline.** There is no call here that can wait forever;
 * that is the whole point of the interface existing next to `stream_socket_client`
 * (architecture 9.2).
 */
interface Transport
{
    /** The line terminator of both protocols. */
    public const string CRLF = "\r\n";

    /**
     * One line, without its CRLF.
     *
     * @throws ReadTimedOut     when the read timeout elapses
     * @throws ConnectionFailed when the peer closed the connection
     */
    public function readLine(): string;

    /**
     * Exactly `$count` bytes — a literal payload, which may contain CRLF.
     *
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    public function readBytes(int $count): string;

    /**
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    public function write(string $bytes): void;

    /**
     * Negotiate TLS on an already open plaintext connection (`STARTTLS`).
     *
     * Certificate verification applies here exactly as it does for an implicit
     * TLS connection; there is no relaxed mode for the upgrade path.
     *
     * @throws ConnectionFailed when the handshake fails or the certificate does
     *                          not verify
     */
    public function enableCrypto(): void;

    /**
     * Is the stream encrypted right now?
     *
     * Asked before any credential is written. A `false` here is a bug, not a
     * configuration choice.
     */
    public function isEncrypted(): bool;

    public function isOpen(): bool;

    public function close(): void;

    /**
     * `host:port`, for messages and traces. Never carries a credential.
     */
    public function endpoint(): string;
}

<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Net;

use Mailadmin\Infrastructure\Mailstore\ConfigurationInvalid;

/**
 * Where to connect, how long to wait, and what to trust.
 *
 * The three timeouts are separate because they fail for different reasons: a
 * connect timeout is usually a firewall, a read timeout is a server that took
 * the command and is thinking, a write timeout is a full send buffer. Reporting
 * all three as one number makes the first hour of every incident a guess.
 *
 * **There is no field that switches certificate verification off.** The test
 * environment (F5) puts its own CA into the trust store; {@see $caFile} exists
 * so a deployment can name a private CA bundle, not so it can skip the check.
 * That is the difference between M1's open point on TLS and the legacy client's
 * behaviour, and it is enforced by there being nothing to set.
 */
final readonly class Endpoint
{
    public const float DEFAULT_CONNECT_TIMEOUT = 10.0;
    public const float DEFAULT_READ_TIMEOUT = 30.0;
    public const float DEFAULT_WRITE_TIMEOUT = 30.0;

    /** Below this a timeout is a mistake rather than a setting. */
    private const float MIN_TIMEOUT = 0.5;

    private function __construct(
        public string $host,
        public int $port,
        public TlsMode $tlsMode,
        public float $connectTimeout,
        public float $readTimeout,
        public float $writeTimeout,
        public ?string $caFile,
    ) {
    }

    /**
     * @throws ConfigurationInvalid
     */
    public static function of(
        string $host,
        int $port,
        TlsMode $tlsMode = TlsMode::Implicit,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        float $readTimeout = self::DEFAULT_READ_TIMEOUT,
        float $writeTimeout = self::DEFAULT_WRITE_TIMEOUT,
        ?string $caFile = null,
    ): self {
        $name = trim($host);

        if ($name === '') {
            throw ConfigurationInvalid::missingHost();
        }

        if ($port < 1 || $port > 65535) {
            throw ConfigurationInvalid::portOutOfRange($port);
        }

        return new self(
            $name,
            $port,
            $tlsMode,
            self::guardTimeout($connectTimeout, 'connect'),
            self::guardTimeout($readTimeout, 'read'),
            self::guardTimeout($writeTimeout, 'write'),
            self::guardCaFile($caFile),
        );
    }

    public function withPort(int $port): self
    {
        return new self(
            $this->host,
            $port,
            $this->tlsMode,
            $this->connectTimeout,
            $this->readTimeout,
            $this->writeTimeout,
            $this->caFile,
        );
    }

    public function withTlsMode(TlsMode $mode): self
    {
        return new self(
            $this->host,
            $this->port,
            $mode,
            $this->connectTimeout,
            $this->readTimeout,
            $this->writeTimeout,
            $this->caFile,
        );
    }

    /**
     * `host:port`, safe for a log line.
     */
    public function toString(): string
    {
        return $this->host . ':' . $this->port;
    }

    /**
     * The address `stream_socket_client()` takes. `tcp://` even for implicit
     * TLS: the handshake is done by {@see StreamTransport} after the socket is
     * up, so that a connect timeout and a handshake failure are distinguishable.
     */
    public function socketAddress(): string
    {
        return 'tcp://' . $this->host . ':' . $this->port;
    }

    /**
     * @throws ConfigurationInvalid
     */
    private static function guardTimeout(float $seconds, string $which): float
    {
        if ($seconds < self::MIN_TIMEOUT) {
            throw ConfigurationInvalid::timeoutTooSmall($which, $seconds, self::MIN_TIMEOUT);
        }

        return $seconds;
    }

    /**
     * @throws ConfigurationInvalid
     */
    private static function guardCaFile(?string $caFile): ?string
    {
        if ($caFile === null || trim($caFile) === '') {
            return null;
        }

        $path = trim($caFile);

        if (!is_file($path) || !is_readable($path)) {
            // Failing here rather than at handshake time: an unreadable CA
            // bundle makes OpenSSL fall back to the system store, so the
            // connection would succeed against the wrong trust anchor and
            // nobody would notice.
            throw ConfigurationInvalid::caFileUnreadable($path);
        }

        return $path;
    }
}

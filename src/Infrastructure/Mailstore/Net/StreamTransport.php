<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Net;

use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;

/**
 * The real socket: TLS with verification, and a deadline on every wait.
 *
 * Five decisions here are the answer to architecture 9.2's list of things the
 * legacy client got wrong.
 *
 * **1. Verification is not a parameter.** `verify_peer`, `verify_peer_name` and
 * `allow_self_signed` are literals in {@see cryptoContext()}. There is no code
 * path that reaches `stream_socket_client()` with them set differently, so there
 * is nothing to switch, misconfigure or "just for development" comment out. F5
 * makes this workable by putting its own CA in the trust store rather than by
 * turning the check off.
 *
 * **2. The socket and the handshake are separate steps**, even for implicit TLS
 * where PHP would happily do both in one call. A firewall drop and a rejected
 * certificate are different incidents and have to produce different messages.
 *
 * **3. Reads have a timeout and the timeout is checked.** `stream_set_timeout()`
 * alone is not enough: `fgets()` returns `false` both at end of stream and on
 * timeout, and only `stream_get_meta_data()` distinguishes them. Treating the
 * two as the same is how a hung read becomes an empty result.
 *
 * **4. Writes are looped.** `fwrite()` may write fewer bytes than it was given.
 * A partial write of a command line desynchronises the protocol, and the next
 * answer is read against the wrong tag.
 *
 * **5. TLS 1.2 is the floor.** Older versions are not offered.
 */
final class StreamTransport implements Transport
{
    /** A literal larger than this is refused rather than buffered. */
    public const int MAX_LITERAL = 4 * 1024 * 1024;

    /** @var resource|null */
    private $stream;

    private bool $encrypted = false;

    private function __construct(private readonly Endpoint $endpoint)
    {
    }

    /**
     * @throws ConnectionFailed
     * @throws ReadTimedOut
     */
    public static function connect(Endpoint $endpoint): self
    {
        $transport = new self($endpoint);
        $transport->open();

        return $transport;
    }

    public function readLine(): string
    {
        $stream = $this->stream();
        $line = fgets($stream);

        if ($line === false) {
            $this->failRead('a response line');
        }

        $this->guardTimeout($stream, 'a response line');

        return rtrim($line, "\r\n");
    }

    public function readBytes(int $count): string
    {
        if ($count < 0 || $count > self::MAX_LITERAL) {
            throw ConnectionFailed::socket(
                $this->endpoint->toString(),
                sprintf('a literal of %d bytes is outside what we read', $count),
            );
        }

        $stream = $this->stream();
        $buffer = '';

        // Counted down rather than derived from the buffer length on each pass:
        // `fread()` rejects a length below 1, and only this form makes the loop
        // condition itself the proof that it never happens.
        $remaining = $count;

        while ($remaining > 0) {
            $chunk = fread($stream, $remaining);

            if ($chunk === false || $chunk === '') {
                $this->guardTimeout($stream, 'a literal');
                $this->failRead('a literal');
            }

            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }

    public function write(string $bytes): void
    {
        $stream = $this->stream();
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                $this->guardWriteTimeout($stream);

                throw ConnectionFailed::closed($this->endpoint->toString(), 'a write');
            }

            $offset += $written;
        }
    }

    public function enableCrypto(): void
    {
        $stream = $this->stream();
        $context = stream_context_get_options($stream);

        if (($context['ssl'] ?? null) === null) {
            stream_context_set_option($stream, ['ssl' => $this->cryptoContext()]);
        }

        $enabled = @stream_socket_enable_crypto(
            $stream,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        );

        if ($enabled !== true) {
            $this->close();

            throw ConnectionFailed::tls($this->endpoint->toString(), self::lastErrorMessage());
        }

        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->encrypted = false;
    }

    public function endpoint(): string
    {
        return $this->endpoint->toString();
    }

    /**
     * @throws ConnectionFailed
     * @throws ReadTimedOut
     */
    private function open(): void
    {
        $context = stream_context_create(['ssl' => $this->cryptoContext()]);
        $errorNumber = 0;
        $errorMessage = '';

        $stream = @stream_socket_client(
            $this->endpoint->socketAddress(),
            $errorNumber,
            $errorMessage,
            $this->endpoint->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            // `stream_socket_client()` reports a connect timeout as errno 110
            // on Linux and as an empty message elsewhere; the distinction is
            // worth making because the two have different first suspects.
            if ($errorNumber === SOCKET_ETIMEDOUT || stripos($errorMessage, 'timed out') !== false) {
                throw ReadTimedOut::whileConnecting(
                    $this->endpoint->toString(),
                    $this->endpoint->connectTimeout,
                );
            }

            throw ConnectionFailed::socket(
                $this->endpoint->toString(),
                $errorMessage === '' ? 'connection refused' : $errorMessage,
            );
        }

        $this->stream = $stream;
        $this->applyReadTimeout();

        if ($this->endpoint->tlsMode === TlsMode::Implicit) {
            $this->enableCrypto();
        }
    }

    /**
     * The TLS context. Every one of these five options is load-bearing.
     *
     * @return array<string, mixed>
     */
    private function cryptoContext(): array
    {
        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $this->endpoint->host,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];

        if ($this->endpoint->caFile !== null) {
            $options['cafile'] = $this->endpoint->caFile;
        }

        return $options;
    }

    private function applyReadTimeout(): void
    {
        $stream = $this->stream();
        $seconds = (int) floor($this->endpoint->readTimeout);
        $microseconds = (int) round(($this->endpoint->readTimeout - $seconds) * 1_000_000);

        stream_set_timeout($stream, $seconds, $microseconds);
        stream_set_blocking($stream, true);
    }

    /**
     * @return resource
     *
     * @throws ConnectionFailed
     */
    private function stream()
    {
        if (!is_resource($this->stream)) {
            throw ConnectionFailed::closed($this->endpoint->toString(), 'an operation');
        }

        return $this->stream;
    }

    /**
     * @param resource $stream
     *
     * @throws ReadTimedOut
     */
    private function guardTimeout($stream, string $during): void
    {
        $meta = stream_get_meta_data($stream);

        if ($meta['timed_out'] === true) {
            $this->close();

            throw ReadTimedOut::whileReading(
                $this->endpoint->toString(),
                $this->endpoint->readTimeout,
                $during,
            );
        }
    }

    /**
     * @param resource $stream
     *
     * @throws ReadTimedOut
     */
    private function guardWriteTimeout($stream): void
    {
        $meta = stream_get_meta_data($stream);

        if ($meta['timed_out'] === true) {
            $this->close();

            throw ReadTimedOut::whileWriting(
                $this->endpoint->toString(),
                $this->endpoint->writeTimeout,
                'a command',
            );
        }
    }

    /**
     * @throws ConnectionFailed
     */
    private function failRead(string $during): never
    {
        $this->close();

        throw ConnectionFailed::closed($this->endpoint->toString(), $during);
    }

    /**
     * The OpenSSL message behind a failed handshake, flattened to one line.
     */
    private static function lastErrorMessage(): string
    {
        $error = error_get_last();
        $message = $error['message'] ?? 'the handshake failed';

        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }
}

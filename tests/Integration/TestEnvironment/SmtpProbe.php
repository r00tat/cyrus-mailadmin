<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use RuntimeException;

/**
 * The smallest SMTP client that can answer "does SMTP-AUTH work over STARTTLS".
 *
 * Same reasoning as {@see ImapProbe}: scaffolding for F5, not a component. The
 * application never sends mail — it administers a mail server — so nothing in
 * `src/` will grow out of this.
 *
 * The connection starts in the clear and must be upgraded before AUTH, because
 * `smtpd_tls_auth_only = yes` refuses AUTH otherwise. That refusal is itself
 * worth a test.
 */
final class SmtpProbe
{
    private const int TIMEOUT_SECONDS = 10;

    /** @var resource */
    private $stream;

    private bool $encrypted = false;

    private function __construct(mixed $stream)
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('SmtpProbe needs an open stream.');
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, self::TIMEOUT_SECONDS);

        [$code] = $this->readResponse();

        if ($code !== 220) {
            throw new RuntimeException('The SMTP server did not greet us, code ' . $code);
        }
    }

    public static function connect(?string $host = null, ?int $port = null): self
    {
        $host ??= TestEnvironment::mailHost();
        $port ??= TestEnvironment::submissionPort();

        $stream = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $error,
            self::TIMEOUT_SECONDS,
        );

        if ($stream === false) {
            throw new RuntimeException(sprintf(
                'Cannot open tcp://%s:%d: %s (%d)',
                $host,
                $port,
                $error === '' ? 'no reason given' : $error,
                $errno,
            ));
        }

        return new self($stream);
    }

    /**
     * @return list<string> the capability lines, without the code
     */
    public function ehlo(string $name = 'testenv.example.test'): array
    {
        [$code, $lines] = $this->command('EHLO ' . $name);

        if ($code !== 250) {
            throw new RuntimeException('EHLO was refused with code ' . $code);
        }

        return $lines;
    }

    public function supports(string $capability): bool
    {
        foreach ($this->ehlo() as $line) {
            if (stripos($line, $capability) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * STARTTLS, with the certificate verified against the test CA.
     */
    public function startTls(?string $caFile = null, ?string $peerName = null): void
    {
        $caFile ??= TestEnvironment::caFile();
        $peerName ??= TestEnvironment::CERTIFICATE_NAME;

        [$code] = $this->command('STARTTLS');

        if ($code !== 220) {
            throw new RuntimeException('STARTTLS was refused with code ' . $code);
        }

        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $peerName,
            'SNI_enabled' => true,
        ];

        if ($caFile !== null) {
            $options['cafile'] = $caFile;
        }

        foreach ($options as $name => $value) {
            stream_context_set_option($this->stream, 'ssl', $name, $value);
        }

        $enabled = @stream_socket_enable_crypto(
            $this->stream,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        );

        if ($enabled !== true) {
            throw new RuntimeException('The TLS handshake after STARTTLS failed.');
        }

        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * AUTH PLAIN. Returns the response code: 235 on success, 535 on a rejected
     * credential, 538 when the server insists on TLS first.
     */
    public function authPlain(string $username, string $password): int
    {
        $credential = base64_encode("\0" . $username . "\0" . $password);

        [$code] = $this->command('AUTH PLAIN ' . $credential);

        return $code;
    }

    /**
     * @return array{int, list<string>}
     */
    public function command(string $command): array
    {
        if (fwrite($this->stream, $command . "\r\n") === false) {
            throw new RuntimeException('Writing to the SMTP connection failed.');
        }

        return $this->readResponse();
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, "QUIT\r\n");
            fclose($this->stream);
        }
    }

    /**
     * @return array{int, list<string>}
     */
    private function readResponse(): array
    {
        $code = 0;
        $lines = [];

        while (true) {
            $line = fgets($this->stream);

            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);

                throw new RuntimeException($meta['timed_out']
                    ? 'The SMTP server did not answer within ' . self::TIMEOUT_SECONDS . ' seconds.'
                    : 'The SMTP connection closed unexpectedly.');
            }

            $line = rtrim($line, "\r\n");

            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $match) !== 1) {
                throw new RuntimeException('Unreadable SMTP response line: ' . $line);
            }

            $code = (int) $match[1];
            $lines[] = $match[3];

            if ($match[2] === ' ') {
                return [$code, $lines];
            }
        }
    }
}

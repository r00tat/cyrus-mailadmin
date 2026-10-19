<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use RuntimeException;

/**
 * The smallest IMAP client that can answer "does this account get in".
 *
 * NOT a preview of M1. M1 writes the client the application uses, with quoting,
 * literals, tags, timeouts and connection reuse (architecture 9.2). This one
 * exists so F5 can prove the environment works *before* M1 exists, and so that
 * a failure in M1 cannot make the environment look broken. It stays here; M1
 * does not build on it.
 *
 * Certificate verification is on and there is no parameter to turn it off. That
 * is the point of shipping a CA with the environment: a test that would need
 * `verify_peer => false` is a test that proves nothing about a client whose job
 * is to verify (architecture 9.2, finding S-5).
 */
final class ImapProbe
{
    private const int TIMEOUT_SECONDS = 10;

    /** @var resource */
    private $stream;

    private int $tag = 0;

    private function __construct(mixed $stream)
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('ImapProbe needs an open stream.');
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, self::TIMEOUT_SECONDS);

        $greeting = $this->readLine();

        if (!str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('The server did not greet us: ' . $greeting);
        }
    }

    /**
     * Implicit TLS on 993.
     *
     * @param string|null $caFile null uses the system trust store — which is
     *                            where the app container puts the test CA.
     */
    public static function connect(
        ?string $host = null,
        ?int $port = null,
        ?string $caFile = null,
        ?string $peerName = null,
    ): self {
        $host ??= TestEnvironment::mailHost();
        $port ??= TestEnvironment::imapsPort();
        $caFile ??= TestEnvironment::caFile();
        $peerName ??= TestEnvironment::CERTIFICATE_NAME;

        return new self(self::open('ssl://' . $host . ':' . $port, $caFile, $peerName));
    }

    /**
     * Deliberately hands the TLS layer a trust store that does not contain our
     * CA, to prove that verification is doing something. Used by exactly one
     * test; nothing else may weaken TLS.
     */
    public static function connectWithoutTrustingTheTestCa(): self
    {
        $empty = tempnam(sys_get_temp_dir(), 'mailadmin-no-ca');

        if ($empty === false) {
            throw new RuntimeException('Cannot create an empty trust store for the negative test.');
        }

        try {
            return new self(self::open(
                'ssl://' . TestEnvironment::mailHost() . ':' . TestEnvironment::imapsPort(),
                $empty,
                TestEnvironment::CERTIFICATE_NAME,
            ));
        } finally {
            @unlink($empty);
        }
    }

    /**
     * SASL PLAIN. Returns true on a tagged OK.
     *
     * @param string $authzid the identity to act as. Empty means "myself";
     *                        anything else is proxy authentication
     *                        (architecture 9.3) and only the administrator may
     *                        do it.
     */
    public function authenticatePlain(string $username, string $password, string $authzid = ''): bool
    {
        $credential = base64_encode($authzid . "\0" . $username . "\0" . $password);

        [$ok] = $this->command('AUTHENTICATE PLAIN ' . $credential);

        return $ok;
    }

    /**
     * @return array{bool, list<string>} success, and every line of the answer
     */
    public function select(string $mailbox): array
    {
        return $this->command(sprintf('SELECT "%s"', $mailbox));
    }

    /**
     * @return list<string> the mailbox names LIST reported
     */
    public function listMailboxes(string $pattern): array
    {
        [, $lines] = $this->command(sprintf('LIST "" "%s"', $pattern));

        $mailboxes = [];

        foreach ($lines as $line) {
            if (preg_match('/^\* LIST \([^)]*\) "[^"]*" "?([^"\r\n]+)"?$/', $line, $match) === 1) {
                $mailboxes[] = $match[1];
            }
        }

        return $mailboxes;
    }

    public function mailboxExists(string $mailbox): bool
    {
        return $this->listMailboxes($mailbox) !== [];
    }

    /**
     * @return array{bool, list<string>}
     */
    public function command(string $command): array
    {
        $tag = sprintf('p%03d', ++$this->tag);

        $this->write($tag . ' ' . $command);

        $lines = [];

        while (true) {
            $line = $this->readLine();
            $lines[] = $line;

            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/', $line, $match) === 1) {
                return [$match[1] === 'OK', $lines];
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @$this->command('LOGOUT');
            fclose($this->stream);
        }
    }

    /**
     * @return resource
     */
    private static function open(string $endpoint, ?string $caFile, string $peerName)
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $peerName,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];

        if ($caFile !== null) {
            $ssl['cafile'] = $caFile;
        }

        $context = stream_context_create(['ssl' => $ssl]);

        $stream = @stream_socket_client(
            $endpoint,
            $errno,
            $error,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new RuntimeException(sprintf(
                'Cannot open %s: %s (%d)',
                $endpoint,
                $error === '' ? 'no reason given' : $error,
                $errno,
            ));
        }

        return $stream;
    }

    private function write(string $line): void
    {
        if (fwrite($this->stream, $line . "\r\n") === false) {
            throw new RuntimeException('Writing to the IMAP connection failed.');
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);

        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);

            throw new RuntimeException($meta['timed_out']
                ? 'The IMAP server did not answer within ' . self::TIMEOUT_SECONDS . ' seconds.'
                : 'The IMAP connection closed unexpectedly.');
        }

        return rtrim($line, "\r\n");
    }
}

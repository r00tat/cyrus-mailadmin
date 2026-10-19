<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Sieve;

use Mailadmin\Infrastructure\Mailstore\AuthenticationFailed;
use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\StreamTransport;
use Mailadmin\Infrastructure\Mailstore\Net\Transport;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;

/**
 * One authenticated ManageSieve session: greeting, `STARTTLS`, SASL, commands,
 * `LOGOUT` (RFC 5804).
 *
 * The sibling of {@see \Mailadmin\Infrastructure\Mailstore\Imap\Connection},
 * over the same {@see Transport}, and deliberately so: certificate handling,
 * timeouts and "no credential on an unencrypted stream" are then implemented
 * once rather than twice. What differs is the grammar, and four differences are
 * worth knowing before changing anything here.
 *
 * **1. There are no command tags.** RFC 5804 has none. The IMAP rule about
 * unique tags therefore has no counterpart to enforce — what replaces it is that
 * a response ends at the first `OK`/`NO`/`BYE` and that this class writes one
 * command and reads to its completion before writing the next. Pipelining would
 * reintroduce exactly the ambiguity the tags exist to prevent in IMAP, so it is
 * not done.
 *
 * **2. A client literal is never negotiated.** `{n+}` is non-synchronising: the
 * octets follow the announcement immediately and the server does not answer with
 * a `+`. So a `PUTSCRIPT` is one write, not two, and the IMAP continuation dance
 * — and its failure mode, writing a payload the server never asked for — does
 * not exist here. Server literals are the plain `{n}` form and are read the same
 * way IMAP reads them.
 *
 * **3. TLS is `STARTTLS` or nothing.** Cyrus's timsieved has no implicit-TLS
 * port and RFC 5804 defines none, so a ManageSieve session begins in the clear
 * and is upgraded before a single credential is written. That is safe *because*
 * of {@see guardEncrypted()}, which asks the transport rather than trusting the
 * configuration, and because there is no `TlsMode` case that means plaintext.
 * The one deployment prerequisite this rests on is Cyrus's `allowstarttls: yes`
 * — off by default, and U-38 a describes what happens without it.
 *
 * **4. Capabilities are re-read after every step that changes them.** RFC 5804
 * requires the server to re-issue them after the TLS handshake and after a
 * successful `AUTHENTICATE`. Both are honoured: what was announced before the
 * handshake came from whoever was on the wire, not necessarily from the server.
 *
 * **The extension list is the reason capabilities matter beyond `SASL`.** A
 * script that requires an extension the server does not implement is rejected in
 * full — `vacation` and the whole rule set with it — and finding that out at
 * `PUTSCRIPT` time is late. {@see sieveExtensions()} lets the caller compare
 * first; M3's open point 2, answered.
 */
final class ManageSieveConnection
{
    /**
     * The mechanism, for the same reason M1 chose it: `PLAIN` is the one that
     * carries an authorisation identity, and proxy authorisation is how a
     * script is written in a user's name without their password
     * (architecture 9.3).
     */
    public const string MECHANISM = 'PLAIN';

    /**
     * Beyond this an argument travels as a literal instead of a quoted string.
     *
     * RFC 5804 caps a quoted string at 1024 characters; a script is always well
     * past that and always goes as a literal.
     */
    private const int QUOTED_MAX_LENGTH = 1024;

    /**
     * A ceiling on the data lines of one response.
     *
     * `LISTSCRIPTS` is the only command that answers with many lines, and an
     * account with four thousand scripts does not exist. Without a ceiling a
     * server that never sends a completion keeps this loop reading until the
     * process dies — the failure mode {@see ReadTimedOut} exists to prevent, one
     * level up.
     */
    private const int MAX_LINES = 4096;

    /** @var array<string, string> upper-case capability name => its value */
    private array $capabilities = [];

    private bool $authenticated = false;

    /** Whom commands act for: `null` is the administrator themselves. */
    private ?string $actingFor = null;

    private function __construct(
        private readonly Transport $transport,
        private readonly SieveTrace $trace,
        private readonly int $maxLiteral,
    ) {
    }

    /**
     * Opens the session and reads the greeting, which *is* the capability
     * response (RFC 5804, section 1.7).
     *
     * @throws ConnectionFailed when the greeting is a `BYE` — usually a server
     *                          refusing the client's address, where continuing
     *                          would produce a timeout instead of a reason
     * @throws ProtocolError
     * @throws ReadTimedOut
     */
    public static function open(
        Transport $transport,
        SieveTrace $trace,
        ?int $maxLiteral = null,
    ): self {
        $connection = new self($transport, $trace, $maxLiteral ?? StreamTransport::MAX_LITERAL);
        $connection->readGreeting();

        return $connection;
    }

    /**
     * The capability names the server announces, upper case.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return array_keys($this->capabilities);
    }

    public function hasCapability(string $name): bool
    {
        return array_key_exists(strtoupper($name), $this->capabilities);
    }

    public function capabilityValue(string $name): ?string
    {
        return $this->capabilities[strtoupper($name)] ?? null;
    }

    /**
     * The Sieve extensions the server implements, lower case and sorted.
     *
     * From the `"SIEVE"` capability, which is a space-separated list. Compared
     * against {@see \Mailadmin\Domain\Sieve\SieveRuleSet::requiredExtensions()}
     * before a script is written.
     *
     * @return list<string>
     */
    public function sieveExtensions(): array
    {
        $announced = $this->capabilityValue('SIEVE') ?? '';
        $words = preg_split('/\s+/', trim($announced), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            return [];
        }

        $extensions = array_values(array_unique(array_map('strtolower', $words)));
        sort($extensions);

        return $extensions;
    }

    public function supportsMechanism(string $mechanism): bool
    {
        $announced = $this->capabilityValue('SASL') ?? '';
        $words = preg_split('/\s+/', trim($announced), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            return false;
        }

        foreach ($words as $word) {
            if (strcasecmp($word, $mechanism) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function actingFor(): ?string
    {
        return $this->actingFor;
    }

    public function isOpen(): bool
    {
        return $this->transport->isOpen();
    }

    /**
     * Upgrades the connection. Never falls back to plaintext.
     *
     * @throws ConnectionFailed when the server does not offer `STARTTLS` — with
     *                          Cyrus that almost always means `allowstarttls` is
     *                          at its default of `no` (U-38 a), which the message
     *                          says, because the fix is a server line and not a
     *                          client setting
     */
    public function startTls(): void
    {
        if ($this->transport->isEncrypted()) {
            return;
        }

        if (!$this->hasCapability('STARTTLS')) {
            throw ConnectionFailed::tls(
                $this->transport->endpoint(),
                'the server does not offer STARTTLS and plaintext is not an option; '
                . 'Cyrus needs `allowstarttls: yes`, which is not its default',
            );
        }

        $this->execute('STARTTLS');
        $this->transport->enableCrypto();

        // RFC 5804, section 2.2: the server re-issues its capabilities after the
        // handshake, and this is not a courtesy — everything announced before it
        // was announced by whoever was on the wire.
        $this->capabilities = [];
        $this->absorb($this->expect('STARTTLS', $this->readResponse()));
    }

    /**
     * Authenticates as the administrator.
     *
     * @throws AuthenticationFailed
     * @throws ConnectionFailed     when the stream is not encrypted
     */
    public function authenticate(Credentials $credentials): void
    {
        $this->performAuthentication($credentials, null);
    }

    /**
     * Authenticates as the administrator and acts as `$target` (architecture
     * 9.3): `authcid` is the administrator, `authzid` is the user.
     *
     * This is how a user's Sieve script is written **without their password**.
     * The legacy system logged in as the user, which stopped being possible the
     * moment passwords were hashed.
     *
     * @throws AuthenticationFailed
     * @throws ConnectionFailed
     */
    public function authenticateFor(Credentials $credentials, string $target): void
    {
        $identity = trim($target);

        if ($identity === '') {
            throw AuthenticationFailed::forProxy(
                $credentials->administrator,
                $target,
                'the authorisation identity is empty',
            );
        }

        $this->performAuthentication($credentials, $identity);
    }

    /**
     * Runs a command and insists it succeeded.
     *
     * @param list<string> $arguments
     *
     * @throws SieveCommandFailed
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    public function execute(string $command, array $arguments = []): SieveResponse
    {
        return $this->expect($command, $this->send($command, $arguments));
    }

    /**
     * Runs a command and hands back whatever came out, `NO` included.
     *
     * For the places where a refusal is an answer rather than a fault:
     * `DELETESCRIPT` on something that may already be gone, `SETACTIVE` on a
     * name that may not be there.
     *
     * @param list<string> $arguments
     *
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    public function send(string $command, array $arguments = []): SieveResponse
    {
        return $this->dispatch($command, $arguments, sensitive: false);
    }

    /**
     * Ends the session politely, then closes the socket either way.
     *
     * A failing `LOGOUT` is not worth an exception: the connection is being
     * given up, and the caller is usually in a `finally`.
     */
    public function logout(): void
    {
        if ($this->transport->isOpen()) {
            try {
                $this->send('LOGOUT');
            } catch (MailstoreError) {
                // Nothing to salvage and nobody left to tell.
            }
        }

        $this->close();
    }

    public function close(): void
    {
        $this->transport->close();
        $this->authenticated = false;
        $this->actingFor = null;
        $this->capabilities = [];
    }

    // -----------------------------------------------------------------

    /**
     * @throws ConnectionFailed
     * @throws ProtocolError
     * @throws ReadTimedOut
     */
    private function readGreeting(): void
    {
        $response = $this->readResponse();

        if ($response->isBye()) {
            throw ConnectionFailed::greetingRefused(
                $this->transport->endpoint(),
                $response->operatorText(),
            );
        }

        if (!$response->isOk()) {
            throw ProtocolError::malformedLine($response->text);
        }

        $this->absorb($response);
    }

    /**
     * @throws AuthenticationFailed
     * @throws ConnectionFailed
     * @throws ProtocolError
     * @throws ReadTimedOut
     */
    private function performAuthentication(Credentials $credentials, ?string $target): void
    {
        $this->guardEncrypted();

        if (!$this->supportsMechanism(self::MECHANISM)) {
            throw AuthenticationFailed::mechanismUnavailable(
                self::MECHANISM,
                $this->transport->endpoint(),
            );
        }

        // ManageSieve carries the SASL initial response on the command line
        // itself (RFC 5804, section 2.1), so there is no continuation to wait
        // for and no window in which a half-sent exchange can be left open.
        $result = $this->dispatch(
            'AUTHENTICATE',
            [self::MECHANISM, self::plainResponse($credentials, $target)],
            sensitive: true,
        );

        if (!$result->isOk()) {
            throw $this->refusal($credentials, $target, $result->operatorText());
        }

        $this->authenticated = true;
        $this->actingFor = $target;

        // RFC 5804, section 2.1: a successful AUTHENTICATE re-issues the
        // capabilities *before* its OK, so they are already in the response we
        // just read. A server that sends none leaves the post-handshake list
        // standing, which is the one that was learned over TLS anyway.
        $this->absorb($result);
    }

    /**
     * `authzid NUL authcid NUL password`, base64 — RFC 4616.
     */
    private static function plainResponse(Credentials $credentials, ?string $target): string
    {
        return base64_encode(
            ($target ?? '') . "\0" . $credentials->administrator . "\0" . $credentials->secret(),
        );
    }

    private function refusal(
        Credentials $credentials,
        ?string $target,
        string $serverText,
    ): AuthenticationFailed {
        return $target === null
            ? AuthenticationFailed::forAdministrator($credentials->administrator, $serverText)
            : AuthenticationFailed::forProxy($credentials->administrator, $target, $serverText);
    }

    /**
     * @throws ConnectionFailed
     */
    private function guardEncrypted(): void
    {
        if ($this->transport->isEncrypted()) {
            return;
        }

        // The line that separates this client from the one in finding S-5. It
        // asks the transport rather than the configuration, because a
        // configuration can be wrong and a socket cannot.
        throw ConnectionFailed::tls(
            $this->transport->endpoint(),
            'the connection is not encrypted; refusing to send credentials',
        );
    }

    /**
     * @param list<string> $arguments
     *
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    private function dispatch(string $command, array $arguments, bool $sensitive): SieveResponse
    {
        $this->trace->sent($command, $arguments, $sensitive);
        $this->transport->write(self::render($command, $arguments));

        $response = $this->readResponse();

        if ($response->isBye()) {
            // The server ended the session. Leaving the socket open would offer
            // the pool a connection whose next read is end-of-stream.
            $this->close();
        }

        return $response;
    }

    /**
     * The bytes of one command.
     *
     * Every argument is a string; the only decision is quoted or literal, and
     * the answer is "literal unless plainly quotable". A quoted string that
     * needed escaping and did not get it would not merely be wrong — the server
     * would read the rest of it as further arguments, which for `PUTSCRIPT` is
     * the difference between storing a script and storing part of one under a
     * name nobody chose.
     *
     * Literals are `{n+}`, the non-synchronising form: the octets follow the
     * announcement immediately, and further arguments follow the octets.
     *
     * @param list<string> $arguments
     */
    private static function render(string $command, array $arguments): string
    {
        $bytes = $command;

        foreach ($arguments as $argument) {
            $bytes .= ' ' . (self::isQuotable($argument)
                ? self::quote($argument)
                : '{' . strlen($argument) . '+}' . Transport::CRLF . $argument);
        }

        return $bytes . Transport::CRLF;
    }

    /**
     * RFC 5804's quoted string holds any UTF-8 octet except CR, LF and NUL, and
     * escapes `"` and `\`. Length is the second limit: the grammar caps it at
     * 1024 characters.
     */
    private static function isQuotable(string $value): bool
    {
        if (strlen($value) > self::QUOTED_MAX_LENGTH) {
            return false;
        }

        return preg_match('/[\r\n\x00]/', $value) !== 1;
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @throws SieveCommandFailed
     */
    private function expect(string $command, SieveResponse $response): SieveResponse
    {
        if (!$response->isOk()) {
            throw SieveCommandFailed::fromResponse($command, $response);
        }

        return $response;
    }

    /**
     * Reads data lines until the completion.
     *
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    private function readResponse(): SieveResponse
    {
        /** @var list<SieveLine> $lines */
        $lines = [];

        while (true) {
            $line = $this->readLine();

            if ($line->statusWord() !== null) {
                return SieveResponse::of($line, $lines);
            }

            $lines[] = $line;

            if (count($lines) > self::MAX_LINES) {
                throw ProtocolError::malformedLine(
                    '<no OK, NO or BYE after ' . self::MAX_LINES . ' lines>',
                );
            }
        }
    }

    /**
     * One logical line: the physical line, plus the octets of any literal it
     * announces.
     *
     * The loop is the same one M1's reader runs, for the same reason: a line may
     * carry more than one literal — `"SIEVE" {42}` is one — and the announcement
     * is only ever at the end of a physical line.
     *
     * **The ceiling is not politeness.** `{n}` is a number the *server* chooses,
     * and without a limit a broken or hostile peer can make this process
     * allocate as much as it likes, which PHP turns into a fatal error rather
     * than a catchable one.
     *
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    private function readLine(): SieveLine
    {
        $text = $this->transport->readLine();

        /** @var list<string> $literals */
        $literals = [];

        while (($announced = self::announcedLiteral($text)) !== null) {
            if ($announced > $this->maxLiteral) {
                throw ProtocolError::literalTooLarge($announced, $this->maxLiteral);
            }

            $bytes = $this->transport->readBytes($announced);

            if (strlen($bytes) !== $announced) {
                throw ProtocolError::truncatedLiteral($announced, strlen($bytes));
            }

            $index = count($literals);
            $literals[] = $bytes;

            // Drop the `{n}`, put the placeholder where it stood, and append
            // whatever followed the octets — which may be another `{m}`.
            $text = substr($text, 0, strlen($text) - strlen('{' . $announced . '}'))
                . SieveLine::placeholder($index)
                . $this->transport->readLine();
        }

        $line = SieveLine::of($text, $literals);
        $this->trace->received($line);

        return $line;
    }

    /**
     * The `{n}` at the very end of a line. Anywhere else the braces are just
     * characters — a Sieve script is full of them.
     */
    private static function announcedLiteral(string $line): ?int
    {
        if (preg_match('/\{(\d+)\}$/', $line, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Takes a capability response as the complete, current list.
     *
     * Replaces rather than extends: an announcement *is* the whole list, and
     * appending would leave `STARTTLS` standing after it has been withdrawn — so
     * {@see hasCapability()} would answer for a state the connection is no
     * longer in. A response that carries no capability line at all leaves the
     * previous list alone, which is what "the server had nothing new to say"
     * means.
     */
    private function absorb(SieveResponse $response): void
    {
        $capabilities = [];

        foreach ($response->lines as $line) {
            $strings = $line->strings();
            $name = $strings[0] ?? null;

            if ($name === null || trim($name) === '') {
                continue;
            }

            $capabilities[strtoupper(trim($name))] = $strings[1] ?? '';
        }

        if ($capabilities === []) {
            return;
        }

        $this->capabilities = $capabilities;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\AuthenticationFailed;
use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\MailstoreError;
use Mailadmin\Infrastructure\Mailstore\Net\Transport;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;

/**
 * One authenticated IMAP session: greeting, TLS, SASL, commands, logout.
 *
 * Everything below the grammar is {@see Transport}'s problem and everything
 * above it is M2's; this class is the part in between, and it is where the four
 * failures architecture 9.2 lists actually get prevented:
 *
 *  - **A credential is never written to an unencrypted stream.** Not "should not
 *    be" — {@see guardEncrypted()} asks the transport and refuses. There is no
 *    configuration that reaches the other branch, because {@see \Mailadmin\Infrastructure\Mailstore\Net\TlsMode}
 *    has no plaintext case.
 *  - **Capabilities announced before `STARTTLS` are discarded.** RFC 3501 is
 *    explicit about this and the reason is not academic: those capabilities were
 *    delivered by whoever was on the wire, not necessarily by the server, so an
 *    attacker could otherwise advertise a weak mechanism.
 *  - **Every command carries its own tag** and a mismatch is a
 *    {@see ProtocolError}. See {@see CommandTag} for what the legacy client's
 *    constant `.` cost.
 *  - **A literal waits for the server's `+`.** If the server answers the
 *    announcement with a completion instead, the payload is *not* sent: those
 *    bytes would be read as the next command.
 *
 * Untagged lines are collected per command, not globally, so that
 * {@see CommandResult::linesWith()} answers "which `* QUOTA` belongs to my
 * `GETQUOTA`" rather than "which ones arrived at some point".
 */
final class Connection
{
    /**
     * The mechanism used for both plain and proxy authentication.
     *
     * `PLAIN` rather than `LOGIN` (the SASL mechanism) or the `LOGIN` command,
     * because it is the only one of the three that carries an authorisation
     * identity — and proxy authorisation is the whole point of architecture 9.3.
     * Sending the secret in the clear is not a concern the mechanism has to
     * solve; TLS already did.
     */
    public const string MECHANISM = 'PLAIN';

    /** @var list<string> upper case, as announced */
    private array $capabilities = [];

    private bool $authenticated = false;

    /** The identity commands run as: `null` for the administrator themselves. */
    private ?string $actingFor = null;

    private function __construct(
        private readonly Transport $transport,
        private readonly ProtocolTrace $trace,
        private readonly ResponseReader $reader,
        private readonly CommandTag $tags,
    ) {
    }

    /**
     * Opens the session: reads the greeting and learns what the server offers.
     *
     * @throws ConnectionFailed when the greeting is a `BYE` — a server that
     *                          refuses in its greeting is usually one that
     *                          refuses the *client's address*, and continuing
     *                          would produce a confusing timeout instead
     * @throws ProtocolError
     * @throws ReadTimedOut
     */
    public static function open(
        Transport $transport,
        ProtocolTrace $trace,
        ?CommandTag $tags = null,
    ): self {
        $connection = new self(
            $transport,
            $trace,
            new ResponseReader($transport, $trace),
            $tags ?? CommandTag::random(),
        );

        $connection->readGreeting();

        return $connection;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function hasCapability(string $name): bool
    {
        return in_array(strtoupper($name), $this->capabilities, true);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * Whom commands currently act for, or `null` for the administrator.
     */
    public function actingFor(): ?string
    {
        return $this->actingFor;
    }

    public function isOpen(): bool
    {
        return $this->transport->isOpen();
    }

    /**
     * Upgrades a plaintext connection (`TlsMode::StartTls`).
     *
     * @throws ConnectionFailed when the server does not offer `STARTTLS`, or the
     *                          handshake fails. Never falls back to plaintext.
     */
    public function startTls(): void
    {
        if ($this->transport->isEncrypted()) {
            return;
        }

        if (!$this->hasCapability('STARTTLS')) {
            throw ConnectionFailed::tls(
                $this->transport->endpoint(),
                'the server does not offer STARTTLS and plaintext is not an option',
            );
        }

        $this->execute(ImapCommand::of('STARTTLS'));
        $this->transport->enableCrypto();

        // Everything learned before the handshake was learned from whoever was
        // on the wire. RFC 3501, section 6.2.1.
        $this->capabilities = [];
        $this->refreshCapabilities();
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
     * Authenticates as the administrator but acts as `$target` (architecture
     * 9.3): `authcid` is the administrator, `authzid` is the user.
     *
     * This is how a folder is created in a user's name **without their
     * password**. The legacy system logged in as the user with their clear-text
     * password, which stopped being possible the moment passwords were hashed
     * and was wrong before that.
     *
     * @throws AuthenticationFailed when the server accepts the administrator but
     *                              refuses the proxy — a distinct message,
     *                              because the fix is a Cyrus `proxyservers`
     *                              entry and not a new password
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
     * @throws CommandFailed when the server answered `NO` or `BAD`
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    public function execute(ImapCommand $command): CommandResult
    {
        $result = $this->send($command);

        if (!$result->isOk()) {
            throw CommandFailed::fromResult($command->name, $result);
        }

        return $result;
    }

    /**
     * Runs a command and hands back whatever came out, `NO` included.
     *
     * For the handful of places where a refusal is an answer rather than a
     * fault: `GETQUOTA` on a mailbox with no quota root, `DELETE` on something
     * that may already be gone.
     *
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    public function send(ImapCommand $command): CommandResult
    {
        $tag = $this->tags->next();
        $parts = $command->parts($tag);
        $last = count($parts) - 1;

        $this->trace->sent($command, $tag);

        /** @var list<ResponseLine> $untagged */
        $untagged = [];

        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $this->trace->sentPayload(strlen($part));
            }

            $this->transport->write($part);

            if ($index === $last) {
                break;
            }

            $early = $this->awaitContinuation($tag, $untagged);

            if ($early !== null) {
                // The server answered the `{n}` announcement instead of asking
                // for the bytes. Writing them now would put a literal where the
                // server expects a command.
                throw ProtocolError::continuationRefused($command->name, $early->text);
            }
        }

        return $this->readCompletion($tag, $untagged);
    }

    /**
     * Ends the session politely, then closes the socket either way.
     *
     * A `LOGOUT` that fails is not worth an exception: the connection is being
     * given up anyway, and the caller is usually in a `finally`.
     */
    public function logout(): void
    {
        if ($this->transport->isOpen()) {
            try {
                $this->send(ImapCommand::of('LOGOUT'));
            } catch (MailstoreError) {
                // Nothing to salvage and nothing to report — see above.
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

    /**
     * @throws ConnectionFailed
     * @throws ProtocolError
     * @throws ReadTimedOut
     */
    private function readGreeting(): void
    {
        $line = $this->reader->read();

        if (!$line->isUntagged()) {
            throw ProtocolError::malformedLine($line->text);
        }

        $keyword = $line->keyword();

        if ($keyword === 'BYE') {
            throw ConnectionFailed::greetingRefused($this->transport->endpoint(), $line->trace());
        }

        if ($keyword !== 'OK' && $keyword !== 'PREAUTH') {
            throw ProtocolError::malformedLine($line->text);
        }

        // A `PREAUTH` greeting means the server authenticated us by other means
        // — an IMAP-over-a-local-socket setup does this. Recording it keeps
        // `authenticate()` from being required where it cannot work.
        $this->authenticated = $keyword === 'PREAUTH';

        $this->absorbCapabilities($line);

        if ($this->capabilities === []) {
            $this->refreshCapabilities();
        }
    }

    /**
     * @throws AuthenticationFailed
     * @throws ConnectionFailed
     */
    private function performAuthentication(Credentials $credentials, ?string $target): void
    {
        $this->guardEncrypted();

        if (!$this->hasCapability('AUTH=' . self::MECHANISM)) {
            throw AuthenticationFailed::mechanismUnavailable(
                self::MECHANISM,
                $this->transport->endpoint(),
            );
        }

        $command = ImapCommand::of(
            'AUTHENTICATE',
            ImapArgument::atom(self::MECHANISM),
        );

        $tag = $this->tags->next();
        $announcedBefore = $this->capabilities;

        // Rendered from the command object, not from the bytes: that rendering
        // redacts everything after the mechanism name (ProtocolTrace, rule 2).
        $this->trace->sent($command, $tag);
        $this->transport->write($tag . ' AUTHENTICATE ' . self::MECHANISM . Transport::CRLF);

        /** @var list<ResponseLine> $untagged */
        $untagged = [];

        $early = $this->awaitContinuation($tag, $untagged);

        if ($early !== null) {
            throw $this->refusal($credentials, $target, $early->text);
        }

        $response = self::plainResponse($credentials, $target);
        $this->trace->sentPayload(strlen($response));
        $this->transport->write($response . Transport::CRLF);

        $result = $this->readCompletion($tag, $untagged);

        if (!$result->isOk()) {
            throw $this->refusal($credentials, $target, $result->text);
        }

        $this->authenticated = true;
        $this->actingFor = $target;

        // RFC 3501, section 6.2.2: the capability list changes on
        // authentication — `AUTH=` and `STARTTLS` go away, others appear. Cyrus
        // volunteers the new list in the tagged `OK`; a server that did not has
        // to be asked, because until then the old list is a lie.
        //
        // An unchanged list is treated as "not volunteered". A server that
        // re-announced the identical list therefore costs one extra round trip
        // per connection, which is the cheaper mistake of the two.
        if ($this->capabilities === $announcedBefore) {
            $this->capabilities = [];
            $this->refreshCapabilities();
        }
    }

    /**
     * `authzid NUL authcid NUL password`, base64 — RFC 4616.
     *
     * The authorisation identity is empty for a plain login, which is what the
     * RFC prescribes for "act as whoever the credentials identify".
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

        // The one line that separates this client from the one in finding S-5.
        throw ConnectionFailed::tls(
            $this->transport->endpoint(),
            'the connection is not encrypted; refusing to send credentials',
        );
    }

    /**
     * `execute()` rather than `send()`: a server that refuses `CAPABILITY`
     * leaves the list empty, and the next thing that happens is
     * `AuthenticationFailed::mechanismUnavailable()` — a message that sends the
     * operator looking for a SASL configuration problem that is not there.
     */
    private function refreshCapabilities(): void
    {
        $this->execute(ImapCommand::of('CAPABILITY'));
    }

    /**
     * Waits for the server's `+`.
     *
     * @param list<ResponseLine> $untagged
     *
     * @return CommandResult|null `null` when the server asked for more, the
     *                            completion when it decided not to
     *
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    private function awaitContinuation(string $tag, array &$untagged): ?CommandResult
    {
        while (true) {
            $line = $this->reader->read();

            if ($line->isContinuation()) {
                return null;
            }

            if ($line->isUntagged()) {
                $this->absorbCapabilities($line);
                $untagged[] = $line;

                continue;
            }

            return $this->completionOf($line, $tag, $untagged);
        }
    }

    /**
     * @param list<ResponseLine> $untagged
     *
     * @throws ProtocolError
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    private function readCompletion(string $tag, array $untagged): CommandResult
    {
        while (true) {
            $line = $this->reader->read();

            if ($line->isContinuation()) {
                // Nothing outstanding can be continued. Answering it would send
                // bytes the server never asked for.
                throw ProtocolError::malformedLine($line->text);
            }

            if ($line->isUntagged()) {
                $this->absorbCapabilities($line);
                $untagged[] = $line;

                continue;
            }

            return $this->completionOf($line, $tag, $untagged);
        }
    }

    /**
     * @param list<ResponseLine> $untagged
     *
     * @throws ProtocolError
     */
    private function completionOf(ResponseLine $line, string $tag, array $untagged): CommandResult
    {
        $completion = ResponseParser::completion($line, $tag);

        if ($completion === null) {
            throw ProtocolError::malformedLine($line->text);
        }

        $this->absorbCapabilities($line);
        $this->trace->completed();

        return $completion->withUntagged($untagged);
    }

    /**
     * Picks up a capability list wherever it appears: as an untagged
     * `* CAPABILITY …` line, or inside the `[CAPABILITY …]` response code of a
     * greeting or a tagged `OK`. Cyrus uses all three.
     */
    private function absorbCapabilities(ResponseLine $line): void
    {
        if ($line->hasKeyword('CAPABILITY')) {
            // `words()` tokenises the whole line, so the leading `*` is in
            // there too; taking what follows the keyword drops both it and the
            // keyword without depending on how many tokens precede it.
            $this->rememberCapabilities(self::after('CAPABILITY', ResponseParser::words($line)));

            return;
        }

        foreach ($line->tokens() as $token) {
            if (!is_array($token) || !is_string($token[0] ?? null)) {
                continue;
            }

            if (strcasecmp($token[0], 'CAPABILITY') !== 0) {
                continue;
            }

            $words = [];

            foreach (array_slice($token, 1) as $item) {
                if (is_string($item)) {
                    $words[] = $item;
                }
            }

            $this->rememberCapabilities($words);

            return;
        }
    }

    /**
     * Everything after the first occurrence of `$keyword`.
     *
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function after(string $keyword, array $words): array
    {
        foreach ($words as $index => $word) {
            if (strcasecmp($word, $keyword) === 0) {
                return array_values(array_slice($words, $index + 1));
            }
        }

        return [];
    }

    /**
     * A capability announcement is the *complete* list, so it replaces rather
     * than extends. Appending would leave `STARTTLS` and `AUTH=…` standing after
     * they have been withdrawn, and `hasCapability()` would then answer for a
     * state the connection is no longer in.
     *
     * @param list<string> $words
     */
    private function rememberCapabilities(array $words): void
    {
        $capabilities = [];

        foreach ($words as $word) {
            $upper = strtoupper(trim($word));

            if ($upper !== '' && !in_array($upper, $capabilities, true)) {
                $capabilities[] = $upper;
            }
        }

        if ($capabilities === []) {
            return;
        }

        $this->capabilities = $capabilities;
    }
}

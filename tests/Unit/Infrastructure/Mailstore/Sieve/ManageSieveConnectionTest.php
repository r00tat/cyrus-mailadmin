<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Sieve;

use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnection;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveCommandFailed;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveResponse;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveTrace;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The ManageSieve client against a scripted server (RFC 5804).
 *
 * The four cases that carry the weight:
 *
 *  - **no credential on an unencrypted stream** — the transport is asked, not
 *    the configuration (finding S-5);
 *  - **capabilities announced before the handshake are discarded** — they were
 *    announced by whoever was on the wire;
 *  - **a client literal is written in one go** as `{n+}`, with no wait for a
 *    continuation the protocol never sends;
 *  - **an oversized server literal is refused** rather than allocated.
 */
#[CoversClass(ManageSieveConnection::class)]
#[CoversClass(SieveCommandFailed::class)]
#[CoversClass(SieveResponse::class)]
final class ManageSieveConnectionTest extends TestCase
{
    private const string ADMIN = 'cyrus';
    private const string SECRET = 'admin-secret';

    public function testTheGreetingIsTheCapabilityList(): void
    {
        $connection = $this->afterGreeting();

        self::assertTrue($connection->hasCapability('STARTTLS'));
        self::assertSame('Cyrus timsieved v3.10.2', $connection->capabilityValue('IMPLEMENTATION'));
        self::assertSame(
            ['encoded-character', 'fileinto', 'regex', 'vacation'],
            $connection->sieveExtensions(),
        );
    }

    public function testAGreetingThatSaysByeIsARefusedConnection(): void
    {
        $transport = ScriptedTransport::plaintext('BYE "Try again later"');

        try {
            ManageSieveConnection::open($transport, SieveTrace::disabled());
            self::fail('A BYE greeting must not produce a usable connection.');
        } catch (ConnectionFailed $failure) {
            self::assertSame(ErrorCode::UNAVAILABLE, $failure->errorCode());
        }
    }

    /**
     * The single most important line in the package: a password is never written
     * to a stream that is not encrypted, and the question is put to the socket.
     */
    public function testCredentialsAreRefusedOnAnUnencryptedStream(): void
    {
        $connection = $this->afterGreeting();

        try {
            $connection->authenticate(Credentials::of(self::ADMIN, self::SECRET));
            self::fail('A credential must never go onto an unencrypted stream.');
        } catch (ConnectionFailed $failure) {
            self::assertSame(ErrorCode::TLS_REJECTED, $failure->errorCode());
        }
    }

    /**
     * Cyrus has `allowstarttls` off by default (U-38 a), and then ManageSieve has
     * no encrypted path at all. The message says so, because the fix is a line in
     * `imapd.conf` and not a client setting.
     */
    public function testAServerWithoutStarttlsIsRefusedRatherThanUsedInTheClear(): void
    {
        $transport = ScriptedTransport::plaintext(
            '"IMPLEMENTATION" "Cyrus timsieved v3.10.2"',
            '"SIEVE" "vacation"',
            'OK',
        );
        $connection = ManageSieveConnection::open($transport, SieveTrace::disabled());

        try {
            $connection->startTls();
            self::fail('Without STARTTLS the connection must fail, not continue.');
        } catch (ConnectionFailed $failure) {
            self::assertSame(ErrorCode::TLS_REJECTED, $failure->errorCode());
            self::assertStringContainsString('allowstarttls', $failure->getMessage());
        }
    }

    /**
     * RFC 5804, section 2.2. Everything announced before the handshake came from
     * whoever was on the wire — including, in this script, a `SASL` line offering
     * a mechanism the real server never had.
     */
    public function testCapabilitiesAnnouncedBeforeTheHandshakeAreDiscarded(): void
    {
        $transport = ScriptedTransport::plaintext(
            '"IMPLEMENTATION" "impostor"',
            '"SASL" "PLAIN"',
            '"SIEVE" "everything"',
            '"STARTTLS"',
            'OK',
        )->willSay(
            'OK "Begin TLS negotiation now"',
            // After the handshake, the server's real list.
            '"IMPLEMENTATION" "Cyrus timsieved v3.10.2"',
            '"SASL" "PLAIN LOGIN"',
            '"SIEVE" "fileinto vacation"',
            'OK',
        );

        $connection = ManageSieveConnection::open($transport, SieveTrace::disabled());
        $connection->startTls();

        self::assertSame(
            'Cyrus timsieved v3.10.2',
            $connection->capabilityValue('IMPLEMENTATION'),
        );
        self::assertSame(['fileinto', 'vacation'], $connection->sieveExtensions());
        self::assertFalse(
            $connection->hasCapability('STARTTLS'),
            'the pre-handshake list survived the upgrade',
        );
    }

    public function testProxyAuthenticationSendsTheUserAsTheAuthorisationIdentity(): void
    {
        $transport = $this->encryptedGreeting()->willSay('OK "Success"');
        $connection = ManageSieveConnection::open($transport, SieveTrace::disabled());

        $connection->authenticateFor(
            Credentials::of(self::ADMIN, self::SECRET),
            'anna.example.test',
        );

        self::assertTrue($connection->isAuthenticated());
        self::assertSame('anna.example.test', $connection->actingFor());

        $line = $transport->writtenLines()[0] ?? '';

        self::assertStringStartsWith('AUTHENTICATE "PLAIN" "', $line);

        // RFC 4616: authzid NUL authcid NUL password. The user's own password is
        // nowhere in it — architecture 9.3, and the reason folders and scripts
        // can be managed at all once passwords are hashed.
        $encoded = trim(substr($line, strlen('AUTHENTICATE "PLAIN" ')), '"');

        self::assertSame(
            "anna.example.test\0" . self::ADMIN . "\0" . self::SECRET,
            base64_decode($encoded, true),
        );
    }

    public function testARefusedProxyAuthorisationNamesBothIdentities(): void
    {
        $transport = $this->encryptedGreeting()->willSay('NO "not allowed to proxy"');
        $connection = ManageSieveConnection::open($transport, SieveTrace::disabled());

        $this->expectExceptionMessage('refused "cyrus" acting for "anna.example.test"');

        $connection->authenticateFor(
            Credentials::of(self::ADMIN, self::SECRET),
            'anna.example.test',
        );
    }

    /**
     * `{n+}` is non-synchronising: the octets follow the announcement and the
     * server never answers with a `+`. One write, no round trip, and — the point
     * — the length is in **octets**, so a script with umlauts announces more than
     * it has characters.
     */
    public function testALiteralArgumentIsWrittenInOneGoWithItsByteLength(): void
    {
        $transport = $this->authenticated()->willSay('OK "Completed"');
        $connection = $this->openAuthenticated($transport);

        $script = "# Grüße\r\nkeep;\r\n";
        $connection->execute('PUTSCRIPT', ['sieve', $script]);

        $written = $transport->written();
        $expected = 'PUTSCRIPT "sieve" {' . strlen($script) . "+}\r\n" . $script . "\r\n";

        self::assertStringEndsWith($expected, $written);
        self::assertGreaterThan(
            mb_strlen($script),
            strlen($script),
            'the fixture has to contain a multi-byte character for this to prove anything',
        );
    }

    public function testAShortArgumentIsQuotedAndItsSpecialCharactersEscaped(): void
    {
        $transport = $this->authenticated()->willSay('OK');
        $connection = $this->openAuthenticated($transport);

        $connection->execute('DELETESCRIPT', ['odd "name" with \\ in it']);

        self::assertStringEndsWith(
            'DELETESCRIPT "odd \\"name\\" with \\\\ in it"' . "\r\n",
            $transport->written(),
        );
    }

    public function testAServerLiteralIsReadWholeIncludingItsNewlines(): void
    {
        $script = "# mailadmin-managed: v1\r\nkeep;\r\n";
        $transport = $this->authenticated()
            ->willSay('{' . strlen($script) . '}')
            ->willSendBytes($script)
            ->willSay('', 'OK "Completed"');

        $connection = $this->openAuthenticated($transport);
        $response = $connection->execute('GETSCRIPT', ['sieve']);

        self::assertSame([$script], $response->lines[0]->strings());
    }

    /**
     * `{n}` is a number the *server* chooses. Without a ceiling a broken peer
     * decides how much memory this process allocates, and PHP turns that into a
     * fatal error rather than a catchable one.
     */
    public function testAnOversizedServerLiteralIsRefusedRatherThanAllocated(): void
    {
        $transport = $this->encryptedGreeting()->willSay('{9999}');
        $connection = ManageSieveConnection::open(
            $transport,
            SieveTrace::disabled(),
            maxLiteral: 1024,
        );

        $this->expectException(ProtocolError::class);

        $connection->execute('GETSCRIPT', ['sieve']);
    }

    public function testARefusalCarriesItsResponseCodeAndNotItsProse(): void
    {
        $transport = $this->authenticated()->willSay('NO (NONEXISTENT) "Script doesn\'t exist"');
        $connection = $this->openAuthenticated($transport);

        try {
            $connection->execute('DELETESCRIPT', ['gone']);
            self::fail('A NO must not pass as success.');
        } catch (SieveCommandFailed $failure) {
            self::assertSame(ErrorCode::COMMAND_FAILED, $failure->errorCode());
            self::assertTrue($failure->hasResponseCode('nonexistent'));
            self::assertFalse($failure->hasResponseCode('ACTIVE'));
            self::assertFalse($failure->isRetryable());
        }
    }

    public function testAQuotaRefinementIsRecognisedByItsFamily(): void
    {
        $transport = $this->authenticated()->willSay('NO (QUOTA/MAXSCRIPTS) "too many scripts"');
        $connection = $this->openAuthenticated($transport);

        $response = $connection->send('PUTSCRIPT', ['sieve', 'keep;']);

        self::assertFalse($response->isOk());
        self::assertTrue($response->hasCode('QUOTA'));
        self::assertTrue($response->hasCode('quota/maxscripts'));
    }

    /**
     * A `BYE` ends the session. Leaving the socket open would offer the pool a
     * connection whose next read is end of stream.
     */
    public function testAByeClosesTheConnection(): void
    {
        $transport = $this->authenticated()->willSay('BYE "Server shutting down"');
        $connection = $this->openAuthenticated($transport);

        $response = $connection->send('LISTSCRIPTS');

        self::assertTrue($response->isBye());
        self::assertFalse($connection->isOpen());
    }

    public function testLogoutSaysGoodbyeAndClosesTheSocket(): void
    {
        $transport = $this->authenticated()->willSay('OK "Logout Complete"');
        $connection = $this->openAuthenticated($transport);

        $connection->logout();

        self::assertStringEndsWith("LOGOUT\r\n", $transport->written());
        self::assertFalse($connection->isOpen());
        self::assertFalse($connection->isAuthenticated());
    }

    // -----------------------------------------------------------------

    private function afterGreeting(): ManageSieveConnection
    {
        return ManageSieveConnection::open($this->plaintextGreeting(), SieveTrace::disabled());
    }

    private function plaintextGreeting(): ScriptedTransport
    {
        return ScriptedTransport::plaintext(
            '"IMPLEMENTATION" "Cyrus timsieved v3.10.2"',
            '"SIEVE" "fileinto vacation regex encoded-character"',
            '"STARTTLS"',
            '"VERSION" "1.0"',
            'OK',
        );
    }

    private function encryptedGreeting(): ScriptedTransport
    {
        return ScriptedTransport::encrypted(
            '"IMPLEMENTATION" "Cyrus timsieved v3.10.2"',
            '"SASL" "PLAIN LOGIN"',
            '"SIEVE" "fileinto vacation"',
            '"VERSION" "1.0"',
            'OK',
        );
    }

    /** The greeting plus a successful AUTHENTICATE. */
    private function authenticated(): ScriptedTransport
    {
        return $this->encryptedGreeting()->willSay('OK "Success"');
    }

    private function openAuthenticated(ScriptedTransport $transport): ManageSieveConnection
    {
        $connection = ManageSieveConnection::open($transport, SieveTrace::disabled());
        $connection->authenticate(Credentials::of(self::ADMIN, self::SECRET));

        return $connection;
    }
}

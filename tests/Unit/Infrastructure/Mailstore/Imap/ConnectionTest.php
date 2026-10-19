<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\AuthenticationFailed;
use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\Imap\CommandFailed;
use Mailadmin\Infrastructure\Mailstore\Imap\CommandTag;
use Mailadmin\Infrastructure\Mailstore\Imap\Connection;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapArgument;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapCommand;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Connection::class)]
final class ConnectionTest extends TestCase
{
    private const string GREETING = '* OK [CAPABILITY IMAP4REV1 STARTTLS AUTH=PLAIN] ready';
    private const string ADMINISTRATOR = 'cyrus';
    private const string SECRET = 's3cr3t-but-only-in-a-test';

    // -----------------------------------------------------------------
    // Greeting
    // -----------------------------------------------------------------

    public function testTheGreetingSuppliesTheCapabilityList(): void
    {
        $transport = ScriptedTransport::encrypted(self::GREETING);

        $connection = $this->open($transport);

        self::assertTrue($connection->hasCapability('imap4rev1'));
        self::assertTrue($connection->hasCapability('AUTH=PLAIN'));
        self::assertFalse($connection->hasCapability('QUOTA'));
        // Nothing was asked for: the greeting already said it.
        self::assertSame('', $transport->written());
    }

    public function testAGreetingWithoutCapabilitiesIsFollowedByAskingForThem(): void
    {
        $transport = ScriptedTransport::encrypted(
            '* OK Cyrus IMAP ready',
            '* CAPABILITY IMAP4REV1 AUTH=PLAIN QUOTA',
            'A0001 OK Completed',
        );

        $connection = $this->open($transport);

        self::assertSame(['A0001 CAPABILITY'], $transport->writtenLines());
        self::assertTrue($connection->hasCapability('QUOTA'));
    }

    /**
     * A `BYE` greeting is usually the server refusing the *client's address*.
     * Carrying on produces a read timeout thirty seconds later, which describes
     * the wrong problem.
     */
    public function testAByeGreetingIsRefusedImmediately(): void
    {
        $this->expectException(ConnectionFailed::class);

        $this->open(ScriptedTransport::encrypted('* BYE Temporary failure'));
    }

    public function testAPreauthGreetingCountsAsAuthenticated(): void
    {
        $connection = $this->open(
            ScriptedTransport::encrypted('* PREAUTH [CAPABILITY IMAP4REV1] ready'),
        );

        self::assertTrue($connection->isAuthenticated());
    }

    // -----------------------------------------------------------------
    // TLS
    // -----------------------------------------------------------------

    /**
     * The single most important assertion in this file. Finding S-5 is that the
     * legacy client sent the administrator password over port 143 in the clear;
     * this is the line that makes that impossible rather than unlikely.
     */
    public function testCredentialsAreRefusedOnAnUnencryptedConnection(): void
    {
        $transport = ScriptedTransport::plaintext(self::GREETING);
        $connection = $this->open($transport);

        try {
            $connection->authenticate($this->credentials());
            self::fail('A credential must never be written to a plaintext stream.');
        } catch (ConnectionFailed $error) {
            self::assertSame(ErrorCode::TLS_REJECTED, $error->errorCode());
        }

        self::assertStringNotContainsString(
            base64_encode("\0" . self::ADMINISTRATOR . "\0" . self::SECRET),
            $transport->written(),
        );
    }

    /**
     * RFC 3501, section 6.2.1. What was announced before the handshake was
     * announced by whoever held the wire, which may not have been the server —
     * so an attacker could otherwise advertise a mechanism of their choosing.
     */
    public function testCapabilitiesLearnedBeforeStartTlsAreDiscarded(): void
    {
        $transport = ScriptedTransport::plaintext(
            '* OK [CAPABILITY IMAP4REV1 STARTTLS LOGINDISABLED] ready',
            'A0001 OK Begin TLS negotiation now',
            '* CAPABILITY IMAP4REV1 AUTH=PLAIN QUOTA ACL',
            'A0002 OK Completed',
        );

        $connection = $this->open($transport);
        $connection->startTls();

        self::assertTrue($transport->isEncrypted());
        self::assertFalse($connection->hasCapability('LOGINDISABLED'));
        self::assertFalse($connection->hasCapability('STARTTLS'));
        self::assertTrue($connection->hasCapability('QUOTA'));
        self::assertSame(['A0001 STARTTLS', 'A0002 CAPABILITY'], $transport->writtenLines());
    }

    public function testStartTlsIsRefusedWhenTheServerDoesNotOfferIt(): void
    {
        $connection = $this->open(
            ScriptedTransport::plaintext('* OK [CAPABILITY IMAP4REV1] ready'),
        );

        $this->expectException(ConnectionFailed::class);

        $connection->startTls();
    }

    // -----------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------

    public function testAuthenticationSendsTheSaslPlainResponseWithAnEmptyAuthzid(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            '+ ',
            'A0001 OK [CAPABILITY IMAP4REV1 QUOTA ACL] Success',
        );

        $connection = $this->open($transport);
        $connection->authenticate($this->credentials());

        self::assertSame([
            'A0001 AUTHENTICATE PLAIN',
            base64_encode("\0" . self::ADMINISTRATOR . "\0" . self::SECRET),
        ], $transport->writtenLines());

        self::assertTrue($connection->isAuthenticated());
        self::assertNull($connection->actingFor());
        // The tagged OK carried the new list, so no second round trip.
        self::assertTrue($connection->hasCapability('ACL'));
    }

    /**
     * Architecture 9.3: the administrator authenticates with their own
     * credentials and names the user as the authorisation identity. That is how
     * a folder is created in a user's name without their password — the thing
     * the legacy system could only do by knowing it.
     */
    public function testProxyAuthenticationPutsTheTargetInTheAuthzidField(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            '+ ',
            'A0001 OK [CAPABILITY IMAP4REV1] Success',
        );

        $connection = $this->open($transport);
        $connection->authenticateFor($this->credentials(), 'anna.example.test');

        self::assertSame(
            base64_encode('anna.example.test' . "\0" . self::ADMINISTRATOR . "\0" . self::SECRET),
            $transport->writtenLines()[1] ?? null,
        );
        self::assertSame('anna.example.test', $connection->actingFor());
    }

    /**
     * `authcid` accepted, `authzid` refused. The fix is a Cyrus `proxyservers`
     * entry, not a new password, and the message has to say so.
     */
    public function testARefusedProxyIsReportedAsAProxyFailure(): void
    {
        $connection = $this->open(ScriptedTransport::encrypted(
            self::GREETING,
            '+ ',
            'A0001 NO Permission denied',
        ));

        try {
            $connection->authenticateFor($this->credentials(), 'anna.example.test');
            self::fail('A refused proxy authorisation must throw.');
        } catch (AuthenticationFailed $error) {
            self::assertSame(ErrorCode::AUTHENTICATION_FAILED, $error->errorCode());
            self::assertStringContainsString('acting for "anna.example.test"', $error->getMessage());
            self::assertStringNotContainsString(self::SECRET, $error->getMessage());
        }
    }

    public function testAServerThatDoesNotOfferPlainIsNotGuessedAt(): void
    {
        $connection = $this->open(ScriptedTransport::encrypted(
            '* OK [CAPABILITY IMAP4REV1 AUTH=GSSAPI] ready',
        ));

        $this->expectException(AuthenticationFailed::class);

        $connection->authenticate($this->credentials());
    }

    /**
     * Some servers answer `AUTHENTICATE` with a refusal instead of a `+`. The
     * response must not be written in that case: the server is no longer inside
     * the exchange, so those bytes would be read as a command.
     */
    public function testAnImmediateRefusalDoesNotSendTheCredential(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            'A0001 NO [AUTHENTICATIONFAILED] Invalid credentials',
        );

        $connection = $this->open($transport);

        try {
            $connection->authenticate($this->credentials());
            self::fail('A refused AUTHENTICATE must throw.');
        } catch (AuthenticationFailed) {
            // expected
        }

        self::assertSame(['A0001 AUTHENTICATE PLAIN'], $transport->writtenLines());
        self::assertFalse($connection->isAuthenticated());
    }

    public function testCapabilitiesAreRefreshedWhenTheServerDoesNotVolunteerThem(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            '+ ',
            'A0001 OK Success',
            '* CAPABILITY IMAP4REV1 QUOTA ACL',
            'A0002 OK Completed',
        );

        $connection = $this->open($transport);
        $connection->authenticate($this->credentials());

        self::assertSame('A0002 CAPABILITY', $transport->writtenLines()[2] ?? null);
        self::assertFalse($connection->hasCapability('AUTH=PLAIN'));
        self::assertTrue($connection->hasCapability('QUOTA'));
    }

    // -----------------------------------------------------------------
    // Commands
    // -----------------------------------------------------------------

    public function testUntaggedLinesAreAttributedToTheCommandThatCausedThem(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            '* QUOTA "user/anna.example.test" (STORAGE 1234 51200)',
            'A0001 OK Completed',
        );

        $result = $this->open($transport)->execute(
            ImapCommand::of('GETQUOTA', ImapArgument::mailbox('user/anna.example.test')),
        );

        self::assertTrue($result->isOk());
        self::assertCount(1, $result->linesWith('QUOTA'));
        self::assertSame(
            'A0001 GETQUOTA "user/anna.example.test"',
            $transport->writtenLines()[0] ?? null,
        );
    }

    public function testANoAnswerBecomesACommandFailedCarryingTheResponseCode(): void
    {
        $connection = $this->open(ScriptedTransport::encrypted(
            self::GREETING,
            'A0001 NO [ALREADYEXISTS] Mailbox already exists',
        ));

        try {
            $connection->execute(ImapCommand::of('CREATE', ImapArgument::mailbox('user/anna')));
            self::fail('A NO answer must throw.');
        } catch (CommandFailed $error) {
            self::assertSame(ErrorCode::COMMAND_FAILED, $error->errorCode());
            self::assertTrue($error->hasResponseCode('alreadyexists'));
            self::assertSame('CREATE', $error->command);
        }
    }

    public function testSendHandsBackARefusalInsteadOfThrowing(): void
    {
        $result = $this->open(ScriptedTransport::encrypted(
            self::GREETING,
            'A0001 NO [NONEXISTENT] No such mailbox',
        ))->send(ImapCommand::of('DELETE', ImapArgument::mailbox('user/gone')));

        self::assertFalse($result->isOk());
        self::assertTrue($result->hasResponseCode('NONEXISTENT'));
    }

    /**
     * The legacy client sent a constant `.` as the tag, so an answer to the
     * previous command was indistinguishable from the answer to this one. With
     * `SETQUOTA` and `SETACL` at the other end that is a silently wrong outcome.
     */
    public function testAnAnswerCarryingTheWrongTagIsAProtocolError(): void
    {
        $connection = $this->open(ScriptedTransport::encrypted(
            self::GREETING,
            'A0009 OK Completed',
        ));

        $this->expectException(ProtocolError::class);

        $connection->execute(ImapCommand::of('NOOP'));
    }

    public function testALiteralWaitsForTheServerToAskForIt(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            '+ go ahead',
            'A0001 OK Completed',
        );

        $this->open($transport)->execute(ImapCommand::of(
            'SETMETADATA',
            ImapArgument::mailbox('user/anna'),
            ImapArgument::literal('abc'),
        ));

        self::assertSame([
            'A0001 SETMETADATA "user/anna" {3}',
            'abc',
        ], $transport->writtenLines());
    }

    public function testARefusedLiteralIsNotWrittenAnyway(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            'A0001 BAD Literal too long',
        );

        $connection = $this->open($transport);

        try {
            $connection->execute(ImapCommand::of(
                'SETMETADATA',
                ImapArgument::mailbox('user/anna'),
                ImapArgument::literal('abc'),
            ));
            self::fail('A refused literal must throw.');
        } catch (ProtocolError $error) {
            self::assertStringContainsString('did not ask for it', $error->getMessage());
        }

        self::assertSame(['A0001 SETMETADATA "user/anna" {3}'], $transport->writtenLines());
    }

    /**
     * The failure the legacy client did not have: it waited on a socket with no
     * deadline, so a server that accepted the connection and then said nothing
     * produced a hung PHP process and an operation whose outcome nobody knew.
     */
    public function testAHangingServerEndsInATimeoutAndNotALoop(): void
    {
        $transport = ScriptedTransport::encrypted(self::GREETING);
        $connection = $this->open($transport);
        $transport->willStall();

        $this->expectException(ReadTimedOut::class);

        $connection->execute(ImapCommand::of('NOOP'));
    }

    public function testSeveralCommandsShareOneConnectionAndCountUp(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            'A0001 OK Completed',
            'A0002 OK Completed',
            'A0003 OK Completed',
        );

        $connection = $this->open($transport);

        foreach ([1, 2, 3] as $unused) {
            $connection->execute(ImapCommand::of('NOOP'));
        }

        self::assertSame(['A0001 NOOP', 'A0002 NOOP', 'A0003 NOOP'], $transport->writtenLines());
    }

    // -----------------------------------------------------------------
    // Ending the session
    // -----------------------------------------------------------------

    public function testLogoutSaysGoodbyeAndClosesTheSocket(): void
    {
        $transport = ScriptedTransport::encrypted(
            self::GREETING,
            '* BYE Logging out',
            'A0001 OK Completed',
        );

        $connection = $this->open($transport);
        $connection->logout();

        self::assertSame(['A0001 LOGOUT'], $transport->writtenLines());
        self::assertFalse($transport->isOpen());
        self::assertFalse($connection->isAuthenticated());
    }

    /**
     * `logout()` is what a `finally` calls. A server that has already gone away
     * must not turn cleanup into a second failure that hides the first.
     */
    public function testLogoutOnAServerThatIsAlreadyGoneStillCloses(): void
    {
        $transport = ScriptedTransport::encrypted(self::GREETING);
        $connection = $this->open($transport);

        $connection->logout();

        self::assertFalse($transport->isOpen());
    }

    private function open(ScriptedTransport $transport): Connection
    {
        return Connection::open(
            $transport,
            ProtocolTrace::disabled(),
            CommandTag::withPrefix('A'),
        );
    }

    private function credentials(): Credentials
    {
        return Credentials::of(self::ADMINISTRATOR, self::SECRET);
    }
}

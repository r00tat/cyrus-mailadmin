<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\AuthenticationFailed;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapCommand;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\TlsMode;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransport;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionPool::class)]
final class ConnectionPoolTest extends TestCase
{
    private const string TAG = ScriptedTransport::TAG;

    /**
     * The rule from `CLAUDE.md`: no connection per list row. Fifty accounts with
     * their quota is fifty `GETQUOTA` commands and must stay one TCP handshake,
     * one TLS handshake and one SASL exchange.
     */
    public function testTheSameIdentityIsServedFromOneSession(): void
    {
        $transport = $this->session()->willSay(
            self::TAG . ' OK Completed',
            self::TAG . ' OK Completed',
        );
        $factory = new ScriptedTransportFactory($transport);
        $pool = $this->pool($factory);

        $first = $pool->administrative();
        $first->execute(ImapCommand::of('NOOP'));
        $second = $pool->administrative();
        $second->execute(ImapCommand::of('NOOP'));

        self::assertSame($first, $second);
        self::assertSame(1, $factory->handedOut());
        self::assertSame(1, $pool->size());
    }

    /**
     * SASL has no way to step back out of an authorisation identity: a session
     * opened with `authzid = anna` runs as anna until it closes. Handing that
     * session to a caller that asked for administrative rights would produce
     * `NO Permission denied` somewhere that cannot explain it.
     */
    public function testAProxiedSessionIsNeverHandedToSomeoneWhoAskedForAnother(): void
    {
        $factory = new ScriptedTransportFactory(
            $this->session(),
            $this->session(),
            $this->session(),
        );
        $pool = $this->pool($factory);

        $administrator = $pool->administrative();
        $anna = $pool->actingFor('anna.example.test');
        $bruno = $pool->actingFor('bruno.example.test');

        self::assertNotSame($administrator, $anna);
        self::assertNotSame($anna, $bruno);
        self::assertNull($administrator->actingFor());
        self::assertSame('anna.example.test', $anna->actingFor());
        self::assertSame(3, $factory->handedOut());
    }

    public function testTheSameProxiedIdentityIsAlsoReused(): void
    {
        $factory = new ScriptedTransportFactory($this->session());
        $pool = $this->pool($factory);

        self::assertSame(
            $pool->actingFor('anna.example.test'),
            $pool->actingFor('  anna.example.test  '),
        );
        self::assertSame(1, $factory->handedOut());
    }

    /**
     * An idle timeout or a restart on the server side must not turn into a
     * failure for the caller: nothing has been sent on the dead socket, so
     * reconnecting is invisible and correct.
     */
    public function testASessionTheServerDroppedIsReplaced(): void
    {
        $factory = new ScriptedTransportFactory($this->session(), $this->session());
        $pool = $this->pool($factory);

        $first = $pool->administrative();
        $first->close();

        self::assertNotSame($first, $pool->administrative());
        self::assertSame(2, $factory->handedOut());
        self::assertSame(1, $pool->size());
    }

    public function testStartTlsIsNegotiatedWhenTheEndpointAsksForIt(): void
    {
        $transport = ScriptedTransport::plaintext(
            '* OK [CAPABILITY IMAP4REV1 STARTTLS] ready',
            self::TAG . ' OK Begin TLS negotiation now',
            '* CAPABILITY IMAP4REV1 AUTH=PLAIN',
            self::TAG . ' OK Completed',
            '+ ',
            self::TAG . ' OK [CAPABILITY IMAP4REV1] Success',
        );

        $pool = $this->pool(
            new ScriptedTransportFactory($transport),
            Endpoint::of('imap.test', 143, TlsMode::StartTls),
        );
        $pool->administrative();

        self::assertTrue($transport->isEncrypted());
    }

    /**
     * A handshake that fails leaves a socket behind, and a file descriptor that
     * nothing closes stays until the worker dies.
     */
    public function testAFailedHandshakeDoesNotLeaveTheSocketOpen(): void
    {
        $transport = ScriptedTransport::encrypted(
            '* OK [CAPABILITY IMAP4REV1 AUTH=PLAIN] ready',
            '+ ',
            self::TAG . ' NO [AUTHENTICATIONFAILED] Invalid credentials',
        );
        $pool = $this->pool(new ScriptedTransportFactory($transport));

        try {
            $pool->administrative();
            self::fail('Wrong credentials must throw.');
        } catch (AuthenticationFailed) {
            // expected
        }

        self::assertFalse($transport->isOpen());
        self::assertSame(0, $pool->size());
    }

    public function testAnEmptyAuthorisationIdentityIsRefusedBeforeConnecting(): void
    {
        $factory = new ScriptedTransportFactory();
        $pool = $this->pool($factory);

        $this->expectException(AuthenticationFailed::class);

        try {
            $pool->actingFor('   ');
        } finally {
            self::assertSame(0, $factory->handedOut());
        }
    }

    public function testClosingTheRequestEndsEverySession(): void
    {
        $factory = new ScriptedTransportFactory(
            $this->session()->willSay('* BYE Logging out', self::TAG . ' OK Completed'),
            $this->session()->willSay('* BYE Logging out', self::TAG . ' OK Completed'),
        );
        $pool = $this->pool($factory);

        $pool->administrative();
        $pool->actingFor('anna.example.test');
        $pool->closeAll();

        self::assertSame(0, $pool->size());

        // Idempotent: the request may end twice on an error path.
        $pool->closeAll();
    }

    /**
     * A greeting, a SASL exchange and nothing else — the prelude every scripted
     * session needs before it can say anything interesting.
     */
    private function session(): ScriptedTransport
    {
        return ScriptedTransport::encrypted(
            '* OK [CAPABILITY IMAP4REV1 AUTH=PLAIN] ready',
            '+ ',
            self::TAG . ' OK [CAPABILITY IMAP4REV1 QUOTA ACL] Success',
        );
    }

    private function pool(ScriptedTransportFactory $factory, ?Endpoint $endpoint = null): ConnectionPool
    {
        return new ConnectionPool(
            $endpoint ?? Endpoint::of('imap.test', 993),
            Credentials::of('cyrus', 'only-in-a-test'),
            $factory,
            ProtocolTrace::disabled(),
        );
    }
}

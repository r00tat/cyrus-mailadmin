<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Imap;

use Mailadmin\Config\Environment;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapArgument;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapCommand;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\Imap\ResponseLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(ProtocolTrace::class)]
final class ProtocolTraceTest extends TestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    public function testATraceIsOffUnlessItIsAskedFor(): void
    {
        $logger = new RecordingLogger();

        $trace = ProtocolTrace::forEnvironment(
            $logger,
            'imap.test:993',
            Environment::from('dev'),
            requested: false,
        );

        self::assertFalse($trace->isEnabled());

        $trace->received(new ResponseLine('* OK ready'));

        self::assertSame([], $logger->messages);
    }

    /**
     * Not a courtesy. A transcript in production records every mailbox name of
     * every customer into a log that is rotated, copied and read by people who
     * have no reason to see them.
     */
    public function testATraceCannotBeSwitchedOnInProduction(): void
    {
        $trace = ProtocolTrace::forEnvironment(
            new RecordingLogger(),
            'imap.test:993',
            Environment::from('prod'),
            requested: true,
        );

        self::assertFalse($trace->isEnabled());
    }

    public function testATraceIsOnWhenAskedForOutsideProduction(): void
    {
        $logger = new RecordingLogger();

        $trace = ProtocolTrace::forEnvironment(
            $logger,
            'imap.test:993',
            Environment::from('dev'),
            requested: true,
        );
        $trace->received(new ResponseLine('* OK ready'));

        self::assertTrue($trace->isEnabled());
        self::assertSame(['imap S: * OK ready'], $logger->messages);
    }

    /**
     * The first two lines of every session are the greeting and the login. This
     * is the assertion behind M1's "no password appears in a log".
     */
    public function testTheAuthenticationExchangeLeavesNoCredentialBehind(): void
    {
        $logger = new RecordingLogger();
        $trace = ProtocolTrace::to($logger, 'imap.test:993');

        $trace->sent(
            ImapCommand::of('AUTHENTICATE', ImapArgument::atom('PLAIN')),
            'A0001',
        );
        // The SASL response: base64 of authzid NUL authcid NUL password.
        $trace->sentPayload(strlen(base64_encode("\0cyrus\0" . self::PASSWORD)));
        $trace->completed();

        $joined = implode("\n", $logger->messages);

        self::assertStringNotContainsString(self::PASSWORD, $joined);
        self::assertStringNotContainsString(base64_encode("\0cyrus\0" . self::PASSWORD), $joined);
        self::assertSame([
            'imap C: A0001 AUTHENTICATE PLAIN',
            'imap C: <redacted>',
        ], $logger->messages);
    }

    /**
     * A literal payload that is *not* part of a sensitive command still does not
     * get rendered — only its length. It may be a Sieve script.
     */
    public function testAnOrdinaryPayloadIsReducedToItsLength(): void
    {
        $logger = new RecordingLogger();
        $trace = ProtocolTrace::to($logger, 'imap.test:993');

        $trace->sent(ImapCommand::of('CREATE', ImapArgument::mailbox('user/anna')), 'A0002');
        $trace->sentPayload(42);

        self::assertSame([
            'imap C: A0002 CREATE "user/anna"',
            'imap C: <42 bytes>',
        ], $logger->messages);
    }

    /**
     * Once a sensitive command has finished, the next payload is no longer part
     * of it — otherwise every later literal would be redacted and the transcript
     * would stop being useful.
     */
    public function testSensitivityDoesNotLeakIntoTheNextCommand(): void
    {
        $logger = new RecordingLogger();
        $trace = ProtocolTrace::to($logger, 'imap.test:993');

        $trace->sent(ImapCommand::of('AUTHENTICATE', ImapArgument::atom('PLAIN')), 'A0001');
        $trace->sentPayload(24);
        $trace->completed();
        $trace->sent(ImapCommand::of('PUTSCRIPT', ImapArgument::literal('keep;')), 'A0002');
        $trace->sentPayload(5);

        self::assertSame('imap C: <5 bytes>', $logger->messages[3] ?? null);
    }

    public function testTheEndpointTravelsAsContextAndNotInTheMessage(): void
    {
        $logger = new RecordingLogger();

        ProtocolTrace::to($logger, 'imap.test:993')->received(new ResponseLine('* OK ready'));

        self::assertSame(['endpoint' => 'imap.test:993'], $logger->contexts[0] ?? null);
    }
}

/**
 * A logger that keeps what it was given. Small enough to live next to the one
 * test that needs it.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /** @var list<array<mixed>> */
    public array $contexts = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
        $this->contexts[] = $context;
    }
}

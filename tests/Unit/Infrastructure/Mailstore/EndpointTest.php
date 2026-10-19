<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore;

use Mailadmin\Infrastructure\Mailstore\ConfigurationInvalid;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\Imap\CommandTag;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\TlsMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Endpoint::class)]
#[CoversClass(TlsMode::class)]
#[CoversClass(Credentials::class)]
#[CoversClass(CommandTag::class)]
final class EndpointTest extends TestCase
{
    // -----------------------------------------------------------------
    // Endpoint
    // -----------------------------------------------------------------

    public function testTheDefaultIsImplicitTlsBecauseItCannotBeStripped(): void
    {
        self::assertSame(TlsMode::Implicit, Endpoint::of('imap.test', 993)->tlsMode);
    }

    /**
     * `tcp://` even for implicit TLS: the handshake happens afterwards, so a
     * firewall drop and a rejected certificate stay distinguishable.
     */
    public function testTheSocketAddressLeavesTheHandshakeToTheTransport(): void
    {
        self::assertSame('tcp://imap.test:993', Endpoint::of('imap.test', 993)->socketAddress());
    }

    public function testAnEmptyHostIsRefused(): void
    {
        $this->expectException(ConfigurationInvalid::class);

        Endpoint::of('   ', 993);
    }

    public function testAPortOutsideTheRangeIsRefused(): void
    {
        $this->expectException(ConfigurationInvalid::class);

        Endpoint::of('imap.test', 70000);
    }

    /**
     * A timeout below the floor is a typo, not a setting — and a sub-second
     * timeout on a TLS handshake fails every time under any load at all.
     */
    public function testATimeoutBelowTheFloorIsRefused(): void
    {
        $this->expectException(ConfigurationInvalid::class);

        Endpoint::of('imap.test', 993, readTimeout: 0.1);
    }

    /**
     * An unreadable bundle makes OpenSSL fall back to the *system* trust store,
     * so the connection succeeds against the wrong anchor and nobody notices.
     * Failing here is the only way that stays visible.
     */
    public function testAnUnreadableCaBundleIsRefusedRatherThanIgnored(): void
    {
        try {
            Endpoint::of('imap.test', 993, caFile: '/nowhere/no-such-ca.pem');
            self::fail('An unreadable CA bundle must not be accepted.');
        } catch (ConfigurationInvalid $error) {
            self::assertSame(ErrorCode::CONFIGURATION_INVALID, $error->errorCode());
            self::assertStringContainsString('system', $error->getMessage());
        }
    }

    public function testAnEmptyCaFileMeansTheSystemStoreOnPurpose(): void
    {
        self::assertNull(Endpoint::of('imap.test', 993, caFile: '  ')->caFile);
    }

    /**
     * There is no member that disables verification. The check is that the enum
     * has exactly the two cases — a `Plaintext` case would be the switch finding
     * S-5 exists to remove.
     */
    public function testThereIsNoPlaintextTransportMode(): void
    {
        self::assertSame(['implicit', 'starttls'], TlsMode::values());
    }

    public function testTheDerivedEndpointsKeepEverythingElse(): void
    {
        $endpoint = Endpoint::of('imap.test', 993, readTimeout: 15.0, connectTimeout: 5.0);
        $derived = $endpoint->withPort(143)->withTlsMode(TlsMode::StartTls);

        self::assertSame(143, $derived->port);
        self::assertSame(TlsMode::StartTls, $derived->tlsMode);
        self::assertSame(15.0, $derived->readTimeout);
        self::assertSame(5.0, $derived->connectTimeout);
        self::assertSame('imap.test:143', $derived->toString());
    }

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    public function testAMissingPasswordIsAConfigurationFaultAndNotAWrongPassword(): void
    {
        try {
            Credentials::of('cyrus', '');
            self::fail('An empty secret must not be accepted.');
        } catch (ConfigurationInvalid $error) {
            self::assertStringContainsString('MAILADMIN_IMAP_ADMIN_PASSWORD', $error->getMessage());
        }
    }

    /**
     * A NUL would terminate one of the three fields of the SASL PLAIN response
     * early, so the server would read a different identity than the configured
     * one.
     */
    public function testANulByteInEitherHalfIsRefused(): void
    {
        $this->expectException(ConfigurationInvalid::class);

        Credentials::of("cy\0rus", 'secret');
    }

    public function testTheSecretIsNotShownByADump(): void
    {
        $credentials = Credentials::of('cyrus', 'hunter2');

        self::assertSame('hunter2', $credentials->secret());
        self::assertStringNotContainsString('hunter2', print_r($credentials, true));
        self::assertStringNotContainsString('hunter2', var_export($credentials->__debugInfo(), true));
    }

    // -----------------------------------------------------------------
    // Command tags
    // -----------------------------------------------------------------

    public function testTagsAdvanceAndAreNeverConstant(): void
    {
        $tags = CommandTag::withPrefix('A');

        self::assertSame('A0000', $tags->current());
        self::assertSame('A0001', $tags->next());
        self::assertSame('A0002', $tags->next());
        self::assertSame('A0002', $tags->current());
    }

    /**
     * The prefix is random so that two connections in the same request stay
     * distinguishable in a transcript.
     */
    public function testARandomPrefixIsTwoLetters(): void
    {
        self::assertMatchesRegularExpression('/^[A-Z]{2}0001$/', CommandTag::random()->next());
    }
}

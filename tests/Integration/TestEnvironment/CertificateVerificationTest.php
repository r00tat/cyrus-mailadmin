<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Certificate verification is on, and it is doing something.
 *
 * The Definition of Done says "no test switches TLS verification off". That is
 * easy to satisfy by accident on a machine where verification never had a
 * chance to fail — so this asserts both halves: the connection with the test CA
 * works, and the same connection without it does not.
 *
 * The point is architecture 9.2 and finding S-5: M1 writes a client whose job
 * includes verifying a certificate. An environment that lets everything through
 * cannot tell whether that client works.
 */
final class CertificateVerificationTest extends TestCase
{
    use RequiresTestEnvironment;

    public function testTheTestCaIsAvailable(): void
    {
        $this->requireTestEnvironment();

        $caFile = TestEnvironment::caFile();

        self::assertNotNull(
            $caFile,
            'No CA certificate found. Run docker/certs/generate.sh, or set '
            . 'MAILADMIN_TEST_CA_FILE.',
        );
        self::assertFileExists($caFile);
    }

    public function testAConnectionWithTheTestCaSucceeds(): void
    {
        $this->requireTestEnvironment();

        $imap = ImapProbe::connect();
        $imap->close();

        // Reaching this line is the assertion: the constructor rejects a
        // greeting that never arrives, and stream_socket_client rejects a
        // certificate that does not verify.
        self::assertTrue(true);
    }

    public function testAConnectionWithoutTheTestCaFails(): void
    {
        $this->requireTestEnvironment();

        $this->expectException(RuntimeException::class);

        ImapProbe::connectWithoutTrustingTheTestCa();
    }

    /**
     * ManageSieve is reachable and offers STARTTLS. M3 needs it; the check
     * belongs here so a broken timsieved is found when the environment comes
     * up, not when M3 starts.
     */
    public function testManageSieveAnswersAndOffersStartTls(): void
    {
        $this->requireTestEnvironment();

        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', TestEnvironment::mailHost(), TestEnvironment::manageSievePort()),
            $errno,
            $error,
            10,
        );

        self::assertIsResource($stream, sprintf(
            'ManageSieve does not answer on %s:%d: %s',
            TestEnvironment::mailHost(),
            TestEnvironment::manageSievePort(),
            $error,
        ));

        stream_set_timeout($stream, 10);

        $greeting = '';

        while (($line = fgets($stream)) !== false) {
            $greeting .= $line;

            if (str_starts_with($line, 'OK')) {
                break;
            }
        }

        fclose($stream);

        self::assertStringContainsString(
            'STARTTLS',
            $greeting,
            'timsieved does not advertise STARTTLS. M3 must not send a credential without it.',
        );
        self::assertStringContainsString('"IMPLEMENTATION"', $greeting);
    }
}

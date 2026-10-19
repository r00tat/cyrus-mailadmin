<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Cyrus;

use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusSieveStore;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Sieve\SieveTrace;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransport;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The adapter against a scripted timsieved.
 *
 * The contract cases live in
 * {@see \Mailadmin\Tests\Integration\Mailstore\CyrusSieveStoreIntegrationTest},
 * where a real server answers. What is left here is what a real server cannot be
 * made to do on demand: refuse **without a response code**, the gap M2 found over
 * IMAP (U-38 d) and the reason this adapter asks a second question instead of
 * reading the server's English.
 *
 * And one thing that is invisible in an integration test but decides whether the
 * package is safe at all: every command runs on a session authorised **as the
 * user**. Against the real server an administrative session would simply write
 * the script into the administrator's own space and answer `OK`.
 */
#[CoversClass(CyrusSieveStore::class)]
#[CoversClass(ManageSieveConnectionPool::class)]
final class CyrusSieveStoreTest extends TestCase
{
    public function testEveryCommandRunsOnASessionAuthorisedAsTheUser(): void
    {
        $transport = $this->session('OK "Completed"');
        $store = $this->storeOn($transport);

        $store->putScript($this->anna(), SieveScript::of('sieve', 'keep;'));

        $authenticate = '';

        foreach ($transport->writtenLines() as $line) {
            if (str_starts_with($line, 'AUTHENTICATE ')) {
                $authenticate = $line;
            }
        }

        $encoded = trim(substr($authenticate, strlen('AUTHENTICATE "PLAIN" ')), '"');
        $decoded = base64_decode($encoded, true);

        self::assertIsString($decoded);
        self::assertStringStartsWith(
            "anna.example.test\0",
            $decoded,
            'the script would have been written into the administrator\'s own space',
        );
    }

    public function testTheSessionIsReusedAcrossOperationsForOneAccount(): void
    {
        $transport = $this->session(
            '"sieve" ACTIVE',
            'OK',
            '"sieve" ACTIVE',
            'OK',
        );
        $factory = new ScriptedTransportFactory($transport);
        $store = new CyrusSieveStore($this->pool($factory));

        $store->scriptNames($this->anna());
        $store->scriptNames($this->anna());

        self::assertSame(
            1,
            $factory->handedOut(),
            'a second connection per operation is the legacy system\'s account list',
        );
    }

    public function testTheActiveScriptIsTheOneMarkedActiveAndNotTheOneNamedSo(): void
    {
        $content = SieveScript::markerLine() . "\r\nkeep;\r\n";
        $transport = $this->session(
            '"ACTIVE"',
            '"sieve" ACTIVE',
            'OK',
            '{' . strlen($content) . '}',
        )->willSendBytes($content)->willSay('', 'OK "Completed"');

        $active = $this->storeOn($transport)->activeScript($this->anna());

        self::assertNotNull($active);
        self::assertSame('sieve', $active->name);
        self::assertSame($content, $active->content);
        self::assertTrue($active->isManaged());
    }

    /**
     * Cyrus 3.10 answers `DELETESCRIPT` on the running script with a bare `NO`.
     * Reading its sentence is what M2 forbids, so the adapter asks `LISTSCRIPTS`
     * and turns the answer into the fact.
     */
    public function testDeletingTheActiveScriptIsRecognisedWithoutAResponseCode(): void
    {
        $transport = $this->session(
            'NO "Active script cannot be deleted"',
            '"sieve" ACTIVE',
            'OK',
        );

        try {
            $this->storeOn($transport)->deleteScript($this->anna(), 'sieve');
            self::fail('Deleting the active script must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_REJECTED, $failure->errorCode());
        }
    }

    /**
     * The other half of the same question: a bare `NO` for a script that is not
     * there at all is a **success**, because the postcondition holds and a repair
     * run has to be able to repeat itself.
     */
    public function testDeletingAScriptThatIsNotThereSucceedsWithoutAResponseCode(): void
    {
        $transport = $this->session(
            'NO "No such script"',
            '"sieve" ACTIVE',
            'OK',
            '"sieve" ACTIVE',
            'OK',
        );

        $this->storeOn($transport)->deleteScript($this->anna(), 'gone');

        // No exception: the postcondition already held. The assertion is that the
        // adapter asked rather than guessed — two LISTSCRIPTS, one for "is it the
        // active one" and one for "is it there at all".
        self::assertSame(
            2,
            substr_count($transport->written(), 'LISTSCRIPTS'),
        );
    }

    public function testActivatingAScriptThatIsNotThereIsRefusedByItsCode(): void
    {
        $transport = $this->session('NO (NONEXISTENT) "no such script"');

        try {
            $this->storeOn($transport)->activate($this->anna(), 'gone');
            self::fail('Activating a missing script must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_SCRIPT_NOT_FOUND, $failure->errorCode());
        }
    }

    public function testDeactivatingSendsSetactiveWithAnEmptyName(): void
    {
        $transport = $this->session('OK "Completed"');

        $this->storeOn($transport)->deactivate($this->anna());

        self::assertStringEndsWith("SETACTIVE \"\"\r\n", $transport->written());
    }

    /**
     * A rejected `PUTSCRIPT` quotes the offending line of the script, and a
     * script holds a vacation message. Enough survives to name the error; the
     * whole of it does not.
     */
    public function testARejectedScriptDoesNotCarryTheWholeServerTextIntoTheMessage(): void
    {
        $prose = str_repeat('Ich bin im Urlaub und lese keine Mail. ', 20);
        $transport = $this->session('NO "line 7: syntax error near ' . $prose . '"');

        try {
            $this->storeOn($transport)->putScript($this->anna(), SieveScript::of('sieve', 'oops'));
            self::fail('A rejected script must not pass as stored.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_REJECTED, $failure->errorCode());
            self::assertStringContainsString('syntax error', $failure->getMessage());
            self::assertLessThan(400, strlen($failure->getMessage()));
        }
    }

    public function testTheServersExtensionListIsReportedLowerCaseAndSorted(): void
    {
        $store = $this->storeOn($this->session());

        self::assertSame(
            ['fileinto', 'vacation'],
            $store->sieveExtensions($this->anna()),
        );
    }

    // -----------------------------------------------------------------

    /**
     * A plaintext greeting, `STARTTLS`, the post-handshake capability list and a
     * successful `AUTHENTICATE` — then whatever the case needs.
     */
    private function session(string ...$lines): ScriptedTransport
    {
        return ScriptedTransport::plaintext(
            '"IMPLEMENTATION" "Cyrus timsieved v3.10.2"',
            '"SIEVE" "fileinto vacation"',
            '"STARTTLS"',
            'OK',
        )->willSay(
            'OK "Begin TLS negotiation now"',
            '"IMPLEMENTATION" "Cyrus timsieved v3.10.2"',
            '"SASL" "PLAIN LOGIN"',
            '"SIEVE" "fileinto vacation"',
            'OK',
            'OK "Success"',
            ...$lines,
        );
    }

    private function storeOn(ScriptedTransport $transport): CyrusSieveStore
    {
        return new CyrusSieveStore($this->pool(new ScriptedTransportFactory($transport)));
    }

    private function pool(ScriptedTransportFactory $factory): ManageSieveConnectionPool
    {
        return new ManageSieveConnectionPool(
            Endpoint::of(host: 'mail.example.test', port: 4190),
            Credentials::of('cyrus', 'admin-secret'),
            $factory,
            SieveTrace::disabled(),
        );
    }

    private function anna(): MailboxIdentity
    {
        return MailboxIdentity::create(
            LocalPart::fromString('anna'),
            DomainName::fromString('example.test'),
            NamingScheme::Dotted,
        );
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore;

use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Sieve\SieveScript;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\InMemorySieveStore;
use Mailadmin\Infrastructure\Mailstore\SieveOperationFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Sieve double, which the vacation and forwarding use cases will be tested
 * against long before M3 exists.
 *
 * The behaviour worth pinning down is architecture 9.4's: a script is written
 * whole, never patched, and an active script that is not ours is not overwritten
 * without being noticed.
 */
#[CoversClass(InMemorySieveStore::class)]
#[CoversClass(SieveOperationFailed::class)]
final class InMemorySieveStoreTest extends TestCase
{
    public function testAnAccountWithoutAScriptHasNoActiveOne(): void
    {
        self::assertNull((new InMemorySieveStore())->activeScript($this->anna()));
        self::assertSame([], (new InMemorySieveStore())->scriptNames($this->anna()));
    }

    /**
     * Storing does not activate. The two are separate so that a server which
     * rejects a script rejects it *before* it becomes the one filtering mail.
     */
    public function testStoringAScriptDoesNotActivateIt(): void
    {
        $store = new InMemorySieveStore();
        $store->putScript($this->anna(), SieveScript::of('mailadmin', 'keep;'));

        self::assertSame(['mailadmin'], $store->scriptNames($this->anna()));
        self::assertNull($store->activeScript($this->anna()));

        $store->activate($this->anna(), 'mailadmin');

        self::assertSame('mailadmin', $store->activeScript($this->anna())?->name);
    }

    /**
     * The double stores exactly what it was given. Normalising the content on
     * the way in would hide the defect worth catching — a renderer producing
     * something the server would refuse.
     */
    public function testTheContentComesBackByteForByte(): void
    {
        $content = SieveScript::markerLine() . "\r\nkeep;\r\n";
        $store = new InMemorySieveStore();
        $store->putScript($this->anna(), SieveScript::of('mailadmin', $content));
        $store->activate($this->anna(), 'mailadmin');

        self::assertSame($content, $store->activeScript($this->anna())?->content);
    }

    /**
     * Architecture 9.4: an active script without the mailadmin marker — usually
     * webcyradm's, which was called `sieve` — is shown, not overwritten.
     */
    public function testAForeignActiveScriptIsRecognisedAsForeign(): void
    {
        $store = new InMemorySieveStore();
        $store->givenForeignScript($this->anna(), "# hand-written\r\nkeep;\r\n");

        $active = $store->activeScript($this->anna());

        self::assertInstanceOf(SieveScript::class, $active);
        self::assertSame(SieveScript::LEGACY_NAME, $active->name);
        self::assertTrue($active->isForeign());
        self::assertFalse($active->isManaged());
    }

    public function testOurOwnScriptIsRecognisedAsOurs(): void
    {
        $store = new InMemorySieveStore();
        $store->putScript(
            $this->anna(),
            SieveScript::of('mailadmin', SieveScript::markerLine() . "\r\nkeep;\r\n"),
        );
        $store->activate($this->anna(), 'mailadmin');

        self::assertTrue($store->activeScript($this->anna())?->isManaged());
    }

    public function testActivatingSomethingThatIsNotThereIsRefused(): void
    {
        $store = new InMemorySieveStore();

        try {
            $store->activate($this->anna(), 'nothing');
            self::fail('Activating a script that does not exist must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_SCRIPT_NOT_FOUND, $failure->errorCode());
        }
    }

    /**
     * Every ManageSieve server refuses this — it would leave the account
     * filtering with something that is gone — so the double refuses it too.
     */
    public function testTheActiveScriptCannotBeDeleted(): void
    {
        $store = new InMemorySieveStore();
        $store->putScript($this->anna(), SieveScript::of('mailadmin', 'keep;'));
        $store->activate($this->anna(), 'mailadmin');

        try {
            $store->deleteScript($this->anna(), 'mailadmin');
            self::fail('Deleting the active script must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::SIEVE_REJECTED, $failure->errorCode());
        }

        self::assertSame(['mailadmin'], $store->scriptNames($this->anna()));
    }

    public function testAnInactiveScriptCanBeDeleted(): void
    {
        $store = new InMemorySieveStore();
        $store->putScript($this->anna(), SieveScript::of('mailadmin', 'keep;'));
        $store->putScript($this->anna(), SieveScript::of('spare', 'keep;'));
        $store->activate($this->anna(), 'mailadmin');

        $store->deleteScript($this->anna(), 'spare');

        self::assertSame(['mailadmin'], $store->scriptNames($this->anna()));
    }

    /**
     * Two accounts do not share a script namespace — a double that let them
     * would make a cross-account bug invisible.
     */
    public function testScriptsBelongToOneAccount(): void
    {
        $store = new InMemorySieveStore();
        $store->putScript($this->anna(), SieveScript::of('mailadmin', 'keep;'));

        self::assertSame([], $store->scriptNames($this->bruno()));
        self::assertNull($store->activeScript($this->bruno()));
    }

    public function testItRecordsWhatItWasAskedAndInWhichOrder(): void
    {
        $store = new InMemorySieveStore();
        $store->putScript($this->anna(), SieveScript::of('mailadmin', 'keep;'));
        $store->activate($this->anna(), 'mailadmin');

        self::assertSame([
            'put anna.example.test mailadmin',
            'activate anna.example.test mailadmin',
        ], $store->operations());

        $store->forget();

        self::assertSame([], $store->operations());
    }

    private function anna(): MailboxIdentity
    {
        return $this->identity('anna');
    }

    private function bruno(): MailboxIdentity
    {
        return $this->identity('bruno');
    }

    private function identity(string $localPart): MailboxIdentity
    {
        return MailboxIdentity::create(
            LocalPart::fromString($localPart),
            DomainName::fromString('example.test'),
            NamingScheme::Dotted,
        );
    }
}

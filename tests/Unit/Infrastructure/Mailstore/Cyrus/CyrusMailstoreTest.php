<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore\Cyrus;

use Mailadmin\Domain\Mailstore\Acl;
use Mailadmin\Domain\Mailstore\AclRight;
use Mailadmin\Domain\Mailstore\MailboxEntry;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Infrastructure\Mailstore\Credentials;
use Mailadmin\Infrastructure\Mailstore\Cyrus\CyrusMailstore;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransport;
use Mailadmin\Tests\Support\Mailstore\ScriptedTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the adapter puts on the wire, and what it makes of what comes back.
 *
 * The behavioural contract runs against F5 (`tests/Integration`); this file is
 * about the handful of decisions that are invisible in a behavioural test
 * because both the right and the wrong command produce an `OK`:
 *
 *  - an unlimited quota is `SETQUOTA … ()` and not `SETQUOTA … (STORAGE 0)` —
 *    the second locks the account out of its own mail;
 *  - an empty ACL is `DELETEACL` and not `SETACL … ""`;
 *  - a refusal becomes a fact only via the response code, never via the text;
 *  - a mailbox on its way out of the `DELETED` namespace is not a mailbox.
 */
#[CoversClass(CyrusMailstore::class)]
final class CyrusMailstoreTest extends TestCase
{
    private const string TAG = ScriptedTransport::TAG;

    public function testAnUnlimitedQuotaRemovesTheQuotaRootRatherThanSettingZero(): void
    {
        $transport = $this->session(self::TAG . ' OK Completed');

        $this->storeOn($transport)->setQuota($this->anna(), Quota::unlimited());

        self::assertSame(
            'SETQUOTA "user/anna.example.test" ()',
            $this->lastCommand($transport),
        );
    }

    public function testALimitOfZeroIsSentAsALimitOfZero(): void
    {
        $transport = $this->session(self::TAG . ' OK Completed');

        $this->storeOn($transport)->setQuota($this->anna(), Quota::ofKibibytes(0));

        self::assertSame(
            'SETQUOTA "user/anna.example.test" (STORAGE 0)',
            $this->lastCommand($transport),
        );
    }

    public function testAQuotaIsReadInKibibytesWithItsUsage(): void
    {
        $transport = $this->session(
            '* QUOTAROOT "user/anna.example.test" "user/anna.example.test"',
            '* QUOTA "user/anna.example.test" (STORAGE 1234 51200)',
            self::TAG . ' OK Completed',
        );

        $quota = $this->storeOn($transport)->getQuota($this->anna());

        self::assertSame(51200, $quota->limitKibibytes());
        self::assertSame(1234, $quota->usedKibibytes());
    }

    /**
     * No quota root is "no limit configured" — a different fact from a limit of
     * zero, and the one the legacy system flattened into the string `NOT-SET`.
     */
    public function testAMailboxWithNoQuotaRootReadsAsUnlimited(): void
    {
        $transport = $this->session(
            '* QUOTAROOT "user/anna.example.test"',
            self::TAG . ' OK Completed',
        );

        self::assertTrue($this->storeOn($transport)->getQuota($this->anna())->isUnlimited());
    }

    public function testAnEmptyAclIsDeletedRatherThanStoredAsNothing(): void
    {
        $transport = $this->session(self::TAG . ' OK Completed');

        $this->storeOn($transport)->setAcl($this->anna(), 'helper', Acl::none());

        self::assertSame(
            'DELETEACL "user/anna.example.test" "helper"',
            $this->lastCommand($transport),
        );
    }

    public function testAnAclIsSentInItsCanonicalOrder(): void
    {
        $transport = $this->session(self::TAG . ' OK Completed');

        $this->storeOn($transport)->setAcl($this->anna(), 'anna.example.test', Acl::fullControl());

        self::assertSame(
            'SETACL "user/anna.example.test" "anna.example.test" "lrswipkxtecda"',
            $this->lastCommand($transport),
        );
    }

    public function testAnAclLineIsReadIntoSubjectsAndRights(): void
    {
        $transport = $this->session(
            '* ACL user/anna.example.test anna.example.test lrswipkxtecda cyrus lrswipkxtecda',
            self::TAG . ' OK Completed',
        );

        $acl = $this->storeOn($transport)->getAcl($this->anna());

        self::assertSame(['anna.example.test', 'cyrus'], $acl->subjects());
        self::assertTrue($acl->grants('cyrus', AclRight::Administer));
    }

    /**
     * RFC 5530: `[ALREADYEXISTS]` is a contract, `NO Mailbox already exists` is
     * an English sentence that differs between versions.
     */
    public function testARefusalIsTranslatedFromItsResponseCode(): void
    {
        $transport = $this->session(self::TAG . ' NO [ALREADYEXISTS] Mailbox already exists');

        try {
            $this->storeOn($transport)->createMailbox($this->anna());
            self::fail('An existing mailbox must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::MAILBOX_EXISTS, $failure->errorCode());
        }
    }

    /**
     * A refusal we have no code for stays what it is. Inventing a meaning would
     * be worse than passing on the one thing that is certainly true.
     */
    public function testARefusalWithoutAKnownCodeIsNotReinterpreted(): void
    {
        $transport = $this->session(
            self::TAG . ' NO Server is in maintenance mode',
            // Cyrus sends no response code, so the adapter asks whether the
            // mailbox is there. It is not, so the refusal stays what it was.
            self::TAG . ' OK Completed',
        );

        try {
            $this->storeOn($transport)->createMailbox($this->anna());
            self::fail('A refusal must throw.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::COMMAND_FAILED, $failure->errorCode());
        }
    }

    /**
     * The postcondition holds either way, and A6 has to be able to finish a
     * repair run rather than trip over what an earlier one already did.
     */
    public function testDeletingSomethingThatIsNotThereSucceeds(): void
    {
        $transport = $this->session(
            // SETACL, then the refused DELETE, then the LIST that establishes
            // the mailbox is genuinely gone.
            self::TAG . ' OK Completed',
            self::TAG . ' NO No such mailbox',
            self::TAG . ' OK Completed',
        );

        $this->storeOn($transport)->deleteMailbox($this->anna());

        self::assertTrue(true, 'no exception');
    }

    /**
     * Cyrus does not give an administrator implicit rights on a user mailbox, so
     * a plain `DELETE` fails with "Permission denied". Granting ourselves the
     * right first is the documented way and leaves nothing behind — the mailbox
     * is gone a moment later.
     */
    public function testDeletingGrantsTheAdministratorTheRightItNeedsFirst(): void
    {
        $transport = $this->session(
            self::TAG . ' OK Completed',
            self::TAG . ' OK Completed',
        );

        $this->storeOn($transport)->deleteMailbox($this->anna());

        $commands = $this->commands($transport);

        self::assertSame(
            'SETACL "user/anna.example.test" "cyrus" "lrswipkxtecda"',
            $commands[count($commands) - 2] ?? null,
        );
        self::assertSame('DELETE "user/anna.example.test"', $commands[count($commands) - 1] ?? null);
    }

    /**
     * Cyrus parks deleted mailboxes in `DELETED/…` until it expunges them.
     * Reporting those fills A6's reconciliation with mailboxes on their way out.
     */
    public function testMailboxesOnTheirWayOutAreNotListed(): void
    {
        $transport = $this->session(
            '* LIST () "/" "user/anna.example.test"',
            '* LIST () "/" "DELETED/user/bruno.example.test/4A5F2B01"',
            self::TAG . ' OK Completed',
        );

        $names = array_map(
            static fn (MailboxEntry $entry): string => $entry->name,
            [...$this->storeOn($transport)->listMailboxes('user/*')],
        );

        self::assertSame(['user/anna.example.test'], $names);
    }

    /**
     * A name that needed a literal on the way in has to survive the way out.
     */
    public function testAListedNameIsDecodedFromModifiedUtf7(): void
    {
        $transport = $this->session(
            '* LIST () "/" "user/anna.example.test/Entw&APw-rfe"',
            self::TAG . ' OK Completed',
        );

        $entries = [...$this->storeOn($transport)->listMailboxes('user/*')];

        self::assertSame('user/anna.example.test/Entwürfe', $entries[0]->name);
    }

    /**
     * `\Noselect` marks a name that only exists to hold children. Counting one
     * as a mailbox is how a reconciliation report grows entries nobody created.
     */
    public function testANoselectNameIsListedButNotSelectable(): void
    {
        $transport = $this->session(
            '* LIST (\\Noselect \\HasChildren) "/" "user"',
            self::TAG . ' OK Completed',
        );

        $entries = [...$this->storeOn($transport)->listMailboxes('%')];

        self::assertFalse($entries[0]->isSelectable());
    }

    public function testExistenceIsAskedWithListAndNotWithSelect(): void
    {
        $transport = $this->session(
            '* LIST () "/" "user/anna.example.test"',
            self::TAG . ' OK Completed',
        );

        self::assertTrue($this->storeOn($transport)->mailboxExists($this->anna()));
        self::assertStringNotContainsString('SELECT', $transport->written());
    }

    /**
     * Architecture 9.3. The whole point of the operation living behind the port
     * is that no implementation is given the chance to ask for a user password.
     */
    public function testDefaultFoldersAreCreatedThroughProxyAuthorisation(): void
    {
        // The only connection this opens is the proxied one: creating folders
        // never needs administrative rights, it needs the user's.
        $proxied = ScriptedTransport::encrypted(
            '* OK [CAPABILITY IMAP4REV1 AUTH=PLAIN] ready',
            '+ ',
            self::TAG . ' OK [CAPABILITY IMAP4REV1] Success',
            self::TAG . ' OK Completed',
            self::TAG . ' OK Completed',
        );

        (new CyrusMailstore($this->poolOf($proxied)))->createDefaultFolders(
            $this->anna(),
            DefaultFolders::of('Sent'),
        );

        $commands = $this->commands($proxied);

        // The *relative* name: a proxied session sees the personal namespace,
        // where a folder of one's own is named by itself. `INBOX/Sent` and the
        // absolute form both succeed and both create the wrong mailbox.
        self::assertSame([
            'CREATE "Sent"',
            'SUBSCRIBE "Sent"',
        ], array_slice($commands, -2));

        // The authzid field of the SASL response names the user, and the
        // password in it is the administrator's.
        self::assertStringContainsString(
            base64_encode('anna.example.test' . "\0cyrus\0" . 'only-in-a-test'),
            $proxied->written(),
        );
    }

    /**
     * With `unixhierarchysep: no` the separator is `.`, and
     * `user.greta.mustermann.example.at` is then ambiguous in a way no care
     * downstream can repair. Refusing to work is the only honest answer.
     */
    public function testAServerWithTheWrongSeparatorIsRefused(): void
    {
        $transport = ScriptedTransport::encrypted(
            '* OK [CAPABILITY IMAP4REV1 AUTH=PLAIN NAMESPACE] ready',
            '+ ',
            self::TAG . ' OK [CAPABILITY IMAP4REV1 NAMESPACE] Success',
            '* NAMESPACE (("" ".")) NIL NIL',
            self::TAG . ' OK Completed',
        );

        try {
            $this->storeOn($transport)->mailboxExists($this->anna());
            self::fail('A dot-separated hierarchy must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::UNSUPPORTED_NAMESPACE, $failure->errorCode());
        }
    }

    public function testTheSeparatorIsCheckedOnceAndNotBeforeEveryCommand(): void
    {
        $transport = $this->session(
            self::TAG . ' OK Completed',
            self::TAG . ' OK Completed',
        );

        $store = $this->storeOn($transport);
        $store->setQuota($this->anna(), Quota::ofMebibytes(50));
        $store->setQuota($this->anna(), Quota::ofMebibytes(60));

        self::assertSame(1, substr_count($transport->written(), 'NAMESPACE' . "\r\n"));
    }

    // -----------------------------------------------------------------

    /**
     * A session that has greeted, authenticated and answered `NAMESPACE`, plus
     * whatever the test scripts after it.
     */
    private function session(string ...$lines): ScriptedTransport
    {
        return ScriptedTransport::encrypted(
            '* OK [CAPABILITY IMAP4REV1 AUTH=PLAIN NAMESPACE] ready',
            '+ ',
            self::TAG . ' OK [CAPABILITY IMAP4REV1 NAMESPACE QUOTA ACL] Success',
            '* NAMESPACE (("" "/")) NIL NIL',
            self::TAG . ' OK Completed',
        )->willSay(...$lines);
    }

    private function storeOn(ScriptedTransport $transport): CyrusMailstore
    {
        return new CyrusMailstore($this->poolOf($transport));
    }

    private function poolOf(ScriptedTransport ...$transports): ConnectionPool
    {
        return new ConnectionPool(
            Endpoint::of('imap.test', 993),
            Credentials::of('cyrus', 'only-in-a-test'),
            new ScriptedTransportFactory(...$transports),
            ProtocolTrace::disabled(),
        );
    }

    /**
     * The command lines the client wrote, tags stripped, so an assertion says
     * what the command was rather than which number it had.
     *
     * @return list<string>
     */
    private function commands(ScriptedTransport $transport): array
    {
        $commands = [];

        foreach ($transport->writtenLines() as $line) {
            if (preg_match('/^[A-Za-z]+\d+ (.*)$/', $line, $matches) === 1) {
                $commands[] = $matches[1];
            }
        }

        return $commands;
    }

    private function lastCommand(ScriptedTransport $transport): ?string
    {
        $commands = $this->commands($transport);

        return $commands === [] ? null : $commands[count($commands) - 1];
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

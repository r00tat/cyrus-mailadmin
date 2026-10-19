<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support\Mailstore;

use Mailadmin\Domain\Mailstore\Acl;
use Mailadmin\Domain\Mailstore\AclRight;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Infrastructure\Mailstore\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every {@see Mailstore} has to show, run against each of them.
 *
 * M2 asks for "the same contract test set against `InMemoryMailstore` and
 * `CyrusMailstore`", and the reason is not symmetry for its own sake: every use
 * case in the application is tested against the double, so a double that is more
 * forgiving than the server turns a green suite into a false promise. Anything
 * asserted here is asserted about both.
 *
 * Each case runs under **both naming schemes**. Under `dotted` the INBOX is
 * `user/anna.example.test` and its Sent folder is `user/anna.example.test/Sent`;
 * under `fqun` they are `user/anna@example.test` and
 * `user/anna/Sent@example.test` — the domain stays at the end of the *whole*
 * name. An implementation that treats a folder as a string prefix of its mailbox
 * passes the first and loses every folder in the second.
 */
abstract class MailstoreContract extends TestCase
{
    /**
     * A store with nothing in it. Called once per test.
     */
    abstract protected function emptyStore(): Mailstore;

    /**
     * The domains the cases below build their identities in.
     *
     * Overridable because "empty" means something different for the two
     * implementations: the double starts empty by construction, while a real
     * Cyrus is shared with F5's fixtures and can only be given a corner of
     * itself. The integration subclass points these at a domain nothing else
     * uses.
     */
    protected static function primaryDomain(): string
    {
        return 'example.test';
    }

    protected static function secondaryDomain(): string
    {
        return 'moved.test';
    }

    /**
     * The mailboxes this test can see, restricted to what it could have made.
     *
     * The same reason: against a real server `listMailboxes('*')` also returns
     * every fixture, so an assertion on the whole listing would be an assertion
     * about F5 rather than about the store.
     *
     * @return list<string>
     */
    protected function namesIn(Mailstore $store): array
    {
        $names = [];

        foreach ($store->listMailboxes('*') as $entry) {
            if (
                str_contains($entry->name, static::primaryDomain())
                || str_contains($entry->name, static::secondaryDomain())
            ) {
                $names[] = $entry->name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return iterable<string, array{NamingScheme}>
     */
    public static function schemes(): iterable
    {
        yield 'dotted' => [NamingScheme::Dotted];
        yield 'fqun' => [NamingScheme::Fqun];
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    #[DataProvider('schemes')]
    public function testAMailboxExistsAfterItIsCreatedAndNotBefore(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);

        self::assertFalse($store->mailboxExists($anna));

        $store->createMailbox($anna);

        self::assertTrue($store->mailboxExists($anna));
    }

    /**
     * Strict on purpose. Adopting a mailbox that is already there would hand a
     * new account whatever the previous owner left in it — the orphan U-22 wants
     * shown rather than absorbed.
     */
    #[DataProvider('schemes')]
    public function testCreatingATwiceExistingMailboxIsRefused(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);

        try {
            $store->createMailbox($anna);
            self::fail('Creating an existing mailbox must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::MAILBOX_EXISTS, $failure->errorCode());
            self::assertFalse($failure->isRetryable());
        }
    }

    #[DataProvider('schemes')]
    public function testTwoAccountsInTheSameDomainDoNotCollide(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $store->createMailbox(self::identity('anna', $scheme));
        $store->createMailbox(self::identity('bruno', $scheme));

        self::assertTrue($store->mailboxExists(self::identity('anna', $scheme)));
        self::assertTrue($store->mailboxExists(self::identity('bruno', $scheme)));
    }

    /**
     * A local part may contain dots (`CLAUDE.md`, rule on the Cyrus user name),
     * and under `dotted` those dots sit next to the domain's. Nothing may read
     * that as a hierarchy.
     */
    #[DataProvider('schemes')]
    public function testALocalPartWithDotsIsNotAHierarchy(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $greta = self::identity('greta.mustermann', $scheme);

        $store->createMailbox($greta);

        self::assertTrue($store->mailboxExists($greta));
        self::assertFalse($store->mailboxExists(self::identity('greta', $scheme)));
    }

    /**
     * A prefix is not a parent. Deleting `anna` must not touch `annabel`.
     */
    #[DataProvider('schemes')]
    public function testDeletingDoesNotTakeANeighbourWithIt(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $annabel = self::identity('annabel', $scheme);
        $store->createMailbox($anna);
        $store->createMailbox($annabel);

        $store->deleteMailbox($anna);

        self::assertFalse($store->mailboxExists($anna));
        self::assertTrue($store->mailboxExists($annabel));
    }

    /**
     * The postcondition holds either way, and a repair run (A6) has to be able
     * to finish rather than trip over what a previous run already did.
     */
    #[DataProvider('schemes')]
    public function testDeletingSomethingThatIsNotThereSucceeds(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();

        $store->deleteMailbox(self::identity('nobody', $scheme));

        self::assertFalse($store->mailboxExists(self::identity('nobody', $scheme)));
    }

    // -----------------------------------------------------------------
    // Folders
    // -----------------------------------------------------------------

    #[DataProvider('schemes')]
    public function testDefaultFoldersAreCreatedAndSubscribed(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);

        $store->createDefaultFolders($anna, DefaultFolders::of('Sent', 'Drafts', 'Trash'));

        $names = $this->namesIn($store);

        self::assertContains($anna->imapFolder('Sent'), $names);
        self::assertContains($anna->imapFolder('Drafts'), $names);
        self::assertContains($anna->imapFolder('Trash'), $names);
    }

    /**
     * A rename that leaves the folders behind passes any test that only looks at
     * the INBOX, and loses a user's Sent folder in production. This is the case
     * that separates the two naming schemes.
     */
    #[DataProvider('schemes')]
    public function testRenamingTakesTheFoldersAlong(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);
        $store->createDefaultFolders($anna, DefaultFolders::of('Sent'));

        $moved = self::identity('annabel', $scheme);
        $store->renameMailbox($anna, $moved);

        $names = $this->namesIn($store);

        self::assertContains($moved->imapMailbox(), $names);
        self::assertContains($moved->imapFolder('Sent'), $names);
        self::assertNotContains($anna->imapMailbox(), $names);
        self::assertNotContains($anna->imapFolder('Sent'), $names);
    }

    /**
     * Moving an account to another domain — the first half of a domain rename
     * (architecture 8.1) and of a naming-scheme change (E-14).
     *
     * Under `dotted` the domain is part of the user name, so this is an ordinary
     * rename. Under `fqun` it crosses Cyrus's domain namespace, and **M2's open
     * point 6 asked whether that works in one step. Answered against F5: it
     * does** — provided the server has `allowusermoves: yes`, which is not the
     * Cyrus default and is therefore a deployment prerequisite rather than an
     * assumption.
     */
    #[DataProvider('schemes')]
    public function testAnAccountCanBeMovedToAnotherDomain(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);

        $moved = self::identity('anna', $scheme, static::secondaryDomain());
        $store->renameMailbox($anna, $moved);

        self::assertTrue($store->mailboxExists($moved));
        self::assertFalse($store->mailboxExists($anna));
    }

    #[DataProvider('schemes')]
    public function testDeletingTakesTheFoldersAlongToo(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);
        $store->createDefaultFolders($anna, DefaultFolders::of('Sent'));

        $store->deleteMailbox($anna);

        self::assertSame([], $this->namesIn($store));
    }

    #[DataProvider('schemes')]
    public function testRenamingOntoAnOccupiedNameIsRefused(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $bruno = self::identity('bruno', $scheme);
        $store->createMailbox($anna);
        $store->createMailbox($bruno);

        try {
            $store->renameMailbox($anna, $bruno);
            self::fail('A rename onto an existing mailbox must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::MAILBOX_EXISTS, $failure->errorCode());
        }
    }

    #[DataProvider('schemes')]
    public function testRenamingSomethingThatIsNotThereIsRefused(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();

        try {
            $store->renameMailbox(
                self::identity('nobody', $scheme),
                self::identity('somebody', $scheme),
            );
            self::fail('Renaming a mailbox that does not exist must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::MAILBOX_NOT_FOUND, $failure->errorCode());
        }
    }

    // -----------------------------------------------------------------
    // Quota
    // -----------------------------------------------------------------

    /**
     * "No quota configured" and "a limit of zero" are different facts, and the
     * legacy system conflated them into the string `NOT-SET` (M2, open point 1).
     */
    #[DataProvider('schemes')]
    public function testAMailboxWithoutAQuotaIsUnlimitedAndNotZero(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);

        $quota = $store->getQuota($anna);

        self::assertTrue($quota->isUnlimited());
        self::assertNull($quota->limitKibibytes());
    }

    #[DataProvider('schemes')]
    public function testAQuotaComesBackAsItWasSet(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);

        $store->setQuota($anna, Quota::ofMebibytes(50));

        self::assertSame(51200, $store->getQuota($anna)->limitKibibytes());
    }

    /**
     * A limit of zero is a real setting — the account keeps its mail and can
     * receive nothing more. It must not be read back as "unlimited", which is
     * the opposite.
     */
    #[DataProvider('schemes')]
    public function testALimitOfZeroIsNotUnlimited(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);

        $store->setQuota($anna, Quota::ofKibibytes(0));

        self::assertFalse($store->getQuota($anna)->isUnlimited());
        self::assertSame(0, $store->getQuota($anna)->limitKibibytes());
    }

    #[DataProvider('schemes')]
    public function testAQuotaCanBeRemovedAgain(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);
        $store->setQuota($anna, Quota::ofMebibytes(50));

        $store->setQuota($anna, Quota::unlimited());

        self::assertTrue($store->getQuota($anna)->isUnlimited());
    }

    #[DataProvider('schemes')]
    public function testAQuotaOnAMailboxThatIsNotThereIsRefused(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();

        try {
            $store->setQuota(self::identity('nobody', $scheme), Quota::ofMebibytes(10));
            self::fail('A quota on a mailbox that does not exist must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::MAILBOX_NOT_FOUND, $failure->errorCode());
        }
    }

    // -----------------------------------------------------------------
    // ACL
    // -----------------------------------------------------------------

    #[DataProvider('schemes')]
    public function testANewMailboxCarriesNoAclOfOurMaking(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);

        self::assertNull($store->getAcl($anna)->for('anyone'));
    }

    #[DataProvider('schemes')]
    public function testAnAclComesBackAsItWasSet(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);

        $store->setAcl($anna, 'anna.example.test', Acl::fullControl());

        self::assertTrue(
            $store->getAcl($anna)->grants('anna.example.test', AclRight::Administer),
        );
    }

    #[DataProvider('schemes')]
    public function testAnEmptyAclRemovesTheEntryRatherThanStoringNothing(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $anna = self::identity('anna', $scheme);
        $store->createMailbox($anna);
        $store->setAcl($anna, 'helper', Acl::readOnly());

        $store->setAcl($anna, 'helper', Acl::none());

        self::assertNull($store->getAcl($anna)->for('helper'));
    }

    #[DataProvider('schemes')]
    public function testAnAclOnAMailboxThatIsNotThereIsRefused(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();

        try {
            $store->setAcl(self::identity('nobody', $scheme), 'anyone', Acl::readOnly());
            self::fail('An ACL on a mailbox that does not exist must be refused.');
        } catch (MailstoreFailure $failure) {
            self::assertSame(ErrorCode::MAILBOX_NOT_FOUND, $failure->errorCode());
        }
    }

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    #[DataProvider('schemes')]
    public function testAListingNamesEveryMailboxItMatches(NamingScheme $scheme): void
    {
        $store = $this->emptyStore();
        $store->createMailbox(self::identity('anna', $scheme));
        $store->createMailbox(self::identity('bruno', $scheme));

        $names = $this->namesIn($store);

        self::assertContains(self::identity('anna', $scheme)->imapMailbox(), $names);
        self::assertContains(self::identity('bruno', $scheme)->imapMailbox(), $names);
    }

    protected static function identity(
        string $localPart,
        NamingScheme $scheme,
        ?string $domain = null,
    ): MailboxIdentity {
        return MailboxIdentity::create(
            LocalPart::fromString($localPart),
            DomainName::fromString($domain ?? static::primaryDomain()),
            $scheme,
        );
    }
}

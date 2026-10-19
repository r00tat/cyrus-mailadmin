<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Mailstore;

use Mailadmin\Domain\Mailstore\MailboxEntry;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Mailstore\MailboxOperationFailed;
use Mailadmin\Tests\Support\Mailstore\MailstoreContract;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The double against the shared contract, plus the two things only it can do:
 * record the order of operations, and be put into a state the API refuses to
 * produce.
 */
#[CoversClass(InMemoryMailstore::class)]
#[CoversClass(MailboxEntry::class)]
#[CoversClass(MailboxOperationFailed::class)]
final class InMemoryMailstoreTest extends MailstoreContract
{
    protected function emptyStore(): Mailstore
    {
        return new InMemoryMailstore();
    }

    /**
     * Architecture 8.1 is a statement about *order*: mailbox first on create,
     * database first on delete. A test that only compares end states cannot tell
     * a correct use case from one that got lucky, so the double records what it
     * was asked and in which sequence.
     */
    public function testItRecordsWhatItWasAskedAndInWhichOrder(): void
    {
        $store = new InMemoryMailstore();
        $anna = self::identity('anna', NamingScheme::Dotted);

        $store->createMailbox($anna);
        $store->createDefaultFolders($anna, DefaultFolders::of('Sent'));
        $store->deleteMailbox($anna);

        self::assertSame([
            'create user/anna.example.test',
            'createFolder user/anna.example.test/Sent',
            'delete user/anna.example.test',
        ], $store->operations());

        $store->forget();

        self::assertSame([], $store->operations());
    }

    public function testDefaultFoldersAreSubscribedAndNotOnlyCreated(): void
    {
        $store = new InMemoryMailstore();
        $anna = self::identity('anna', NamingScheme::Fqun);
        $store->createMailbox($anna);

        $store->createDefaultFolders($anna, DefaultFolders::of('Sent'));

        self::assertTrue($store->isSubscribed($anna, 'Sent'));
        self::assertFalse($store->isSubscribed($anna, 'Drafts'));
    }

    /**
     * A mailbox nobody has an account for — what A6 reconciles and what E-18
     * left in the legacy system for years because nothing could name it.
     */
    public function testAnOrphanCanBeArrangedAndIsListed(): void
    {
        $store = new InMemoryMailstore();
        $store->givenOrphan('user/someone.who.left.example.test');

        $names = array_map(
            static fn (MailboxEntry $entry): string => $entry->name,
            [...$store->listMailboxes('user/*')],
        );

        self::assertSame(['user/someone.who.left.example.test'], $names);
    }

    public function testUsageIsReportedWhenTheTestArrangedIt(): void
    {
        $store = new InMemoryMailstore();
        $anna = self::identity('anna', NamingScheme::Dotted);
        $store->given($anna, usedKibibytes: 30720);

        self::assertSame(30720, $store->getQuota($anna)->usedKibibytes());
    }

    /**
     * `%` stops at the separator, `*` does not — the difference that decides
     * whether a listing of accounts also returns everybody's folders.
     */
    public function testTheTwoImapWildcardsBehaveDifferently(): void
    {
        $store = new InMemoryMailstore();
        $anna = self::identity('anna', NamingScheme::Dotted);
        $store->createMailbox($anna);
        $store->createDefaultFolders($anna, DefaultFolders::of('Sent'));

        self::assertCount(1, [...$store->listMailboxes('user/%')]);
        self::assertCount(2, [...$store->listMailboxes('user/*')]);
    }

    public function testAMailboxWithChildrenSaysSo(): void
    {
        $store = new InMemoryMailstore();
        $anna = self::identity('anna', NamingScheme::Dotted);
        $store->createMailbox($anna);
        $store->createDefaultFolders($anna, DefaultFolders::of('Sent'));

        $entries = [...$store->listMailboxes('user/%')];

        self::assertTrue($entries[0]->hasFlag(MailboxEntry::HAS_CHILDREN));
        self::assertTrue($entries[0]->isUserRoot());
    }
}

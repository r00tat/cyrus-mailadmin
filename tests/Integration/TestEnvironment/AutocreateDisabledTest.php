<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use Mailadmin\Tests\Fixtures\TestAccounts;
use PHPUnit\Framework\TestCase;

/**
 * `autocreate_quota: -1` — the deliberate difference from production
 * (architecture 12.2, finding S-13).
 *
 * WHAT ACTUALLY HAPPENS, measured rather than assumed. Logging in with the
 * wrong spelling does not fail at the door: `allusers` offers both spellings and
 * saslauthd accepts either, so authentication SUCCEEDS. What fails is the step
 * after it — there is no mailbox for that spelling.
 *
 * In production, with `autocreate_quota: 0`, Cyrus fills that gap by creating an
 * empty mailbox. The user then sees an empty INBOX while their mail sits in the
 * other namespace. 33 such mailboxes exist there, two with real messages in
 * them, and webcyradm never showed a single one.
 *
 * With -1 the gap stays a gap. That is the whole point: the wrong spelling has
 * to produce nothing at all, so a package that gets the naming scheme wrong
 * fails loudly here instead of quietly in six months.
 */
final class AutocreateDisabledTest extends TestCase
{
    use RequiresTestEnvironment;

    public function testTheWrongSpellingCreatesNoMailbox(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::bcryptAccount();
        $wrong = $account->wrongSchemeUsername();

        self::assertNotSame($account->storedUsername, $wrong, 'The fixture has no wrong spelling.');

        // Nothing there before.
        self::assertFalse(
            $this->mailboxExistsAccordingToTheAdministrator('user/' . $wrong),
            sprintf('user/%s existed before the test — the environment is not clean.', $wrong),
        );

        $imap = ImapProbe::connect();

        try {
            // The login itself goes through. This is not a bug in the fixture:
            // it is what production does, and it is the first half of how the
            // shadow mailboxes came about.
            self::assertTrue(
                $imap->authenticatePlain($wrong, $account->password),
                'The address spelling no longer authenticates. That contradicts the allusers view '
                . 'and changes what this test is about — check the saslauthd -r flag.',
            );

            // The second half does not.
            [$selected, $lines] = $imap->select('INBOX');

            self::assertFalse($selected, sprintf(
                'SELECT INBOX succeeded for the wrong spelling %s. Either autocreate is back on or '
                . 'a mailbox was created by hand — this is finding S-13 reproducing itself. '
                . 'Server said: %s',
                $wrong,
                implode(' | ', $lines),
            ));
        } finally {
            $imap->close();
        }

        // And nothing was left behind.
        self::assertFalse(
            $this->mailboxExistsAccordingToTheAdministrator('user/' . $wrong),
            sprintf(
                'A shadow mailbox user/%s appeared. `autocreate_quota` in '
                . 'docker/mail/cyrus/imapd.conf must be -1.',
                $wrong,
            ),
        );
    }

    /**
     * The setting itself, read back from the running server rather than from
     * the file we wrote. A mounted-over or edited config would pass a file
     * check and fail here.
     */
    public function testTheRealMailboxOfTheSameAccountIsStillThere(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::bcryptAccount();

        self::assertTrue(
            $this->mailboxExistsAccordingToTheAdministrator($account->mailbox()),
            sprintf(
                '%s is missing. The fixtures are loaded but the mailboxes are not — run '
                . '`bin/test-env seed`.',
                $account->mailbox(),
            ),
        );
    }

    /**
     * The fqun account, for the mirror image of the same problem. E-14 warns
     * that switching a domain to `fqun` moves the trap rather than removing it.
     */
    public function testTheWrongSpellingOfAnFqunAccountCreatesNoMailboxEither(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::byStoredUsername('hans@beispiel.invalid');
        $wrong = $account->wrongSchemeUsername();

        $imap = ImapProbe::connect();

        try {
            // No `allusers` row spells an fqun account with dots, so this one
            // does not even authenticate.
            self::assertFalse(
                $imap->authenticatePlain($wrong, $account->password),
                sprintf('The dotted spelling %s of an fqun account authenticated.', $wrong),
            );
        } finally {
            $imap->close();
        }

        self::assertFalse(
            $this->mailboxExistsAccordingToTheAdministrator('user/' . $wrong),
            sprintf('A shadow mailbox user/%s appeared.', $wrong),
        );
    }

    private function mailboxExistsAccordingToTheAdministrator(string $mailbox): bool
    {
        $imap = ImapProbe::connect();

        try {
            self::assertTrue(
                $imap->authenticatePlain(
                    TestEnvironment::adminUser(),
                    TestEnvironment::adminPassword(),
                ),
                'The Cyrus administrator could not log in.',
            );

            return $imap->mailboxExists($mailbox);
        } finally {
            $imap->close();
        }
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use Mailadmin\Tests\Fixtures\TestAccount;
use Mailadmin\Tests\Fixtures\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The smoke test the work package asks for: the whole chain, once, end to end.
 *
 *     password_hash() -> accountuser -> view allusers -> pam_mysql -> crypt(3)
 *                     -> IMAP login
 *
 * If this passes, the environment is doing its job. If it fails, the message
 * says which link broke — which is why it walks the chain in steps instead of
 * asserting the last one.
 *
 * The reason this test exists at all: the proof that bcrypt works with
 * `pam_mysql` had to be produced on the production server, because there was
 * nowhere else to produce it (architecture 12). There is now.
 */
final class AuthenticationChainTest extends TestCase
{
    use RequiresTestEnvironment;

    public function testTheWholeChainWithABcryptAccount(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::bcryptAccount();

        // Link 1 — the hash is what password_hash() produces, and it is stored
        // unmangled. A column too narrow for 60 characters would show up here.
        $statement = TestEnvironment::connection()->prepare(
            'SELECT password FROM accountuser WHERE username = :username',
        );
        $statement->execute(['username' => $account->storedUsername]);
        $stored = $statement->fetchColumn();

        self::assertIsString($stored);
        self::assertStringStartsWith('$2y$', $stored, 'The stored hash is not bcrypt.');
        self::assertSame(60, strlen($stored), 'The hash was truncated on the way into the column.');
        self::assertTrue(
            password_verify($account->password, $stored),
            'PHP cannot verify its own hash — the fixture and the catalogue disagree.',
        );

        // Link 2 — the view offers the account under both spellings, which is
        // what lets a user log in as either.
        $view = TestEnvironment::connection()->prepare(
            'SELECT username, password FROM allusers WHERE username IN (:cyrus, :address)',
        );
        $view->execute([
            'cyrus' => $account->storedUsername,
            'address' => $account->address,
        ]);
        $rows = $view->fetchAll();

        self::assertCount(2, $rows, 'allusers does not offer both spellings of the account.');

        foreach ($rows as $row) {
            self::assertSame($stored, $row['password'], 'The view reports a different hash.');
        }

        // Links 3 to 5 — saslauthd, pam_mysql and crypt(3) are inside the mail
        // container and have no separate interface. An IMAP login is the
        // observation that covers all three.
        $imap = ImapProbe::connect();

        try {
            self::assertTrue(
                $imap->authenticatePlain($account->storedUsername, $account->password),
                'IMAP refused a correct bcrypt password — the chain is broken somewhere between '
                . 'saslauthd and crypt(3). `docker compose -f docker/compose.yaml logs mail` shows '
                . 'the saslauthd verdict.',
            );

            [$selected] = $imap->select('INBOX');
            self::assertTrue($selected, 'The account authenticated but has no INBOX.');
        } finally {
            $imap->close();
        }
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::bcryptAccount();
        $imap = ImapProbe::connect();

        try {
            self::assertFalse(
                $imap->authenticatePlain($account->storedUsername, $account->password . '-wrong'),
                'IMAP accepted a wrong password.',
            );
        } finally {
            $imap->close();
        }
    }

    /**
     * The backward path is part of the promise (F5, Definition of Done): an
     * account that has not been touched since webcyradm wrote it must keep
     * working. There is no forced migration (E-13, E-18).
     *
     * @return iterable<string, array{TestAccount}>
     */
    public static function authenticatingAccountProvider(): iterable
    {
        foreach (TestAccounts::authenticating() as $account) {
            yield $account->hashFormat . ' / ' . $account->storedUsername => [$account];
        }
    }

    #[DataProvider('authenticatingAccountProvider')]
    public function testEveryFormatCryptCanReadAuthenticates(TestAccount $account): void
    {
        $this->requireFixtures();

        $imap = ImapProbe::connect();

        try {
            self::assertTrue(
                $imap->authenticatePlain($account->storedUsername, $account->password),
                sprintf(
                    'The %s account %s was refused. Either crypt(3) in the mail container cannot '
                    . 'read the format, or the matching pam_mysql line is missing.',
                    $account->hashFormat,
                    $account->storedUsername,
                ),
            );
        } finally {
            $imap->close();
        }
    }

    /**
     * The two formats no pam_mysql line covers.
     *
     * Not a defect: production configures `crypt=1` and `crypt=4` and nothing
     * else, so an account stored as plaintext or as MySQL PASSWORD() cannot
     * authenticate there either. The test pins the limit so that adding a line
     * is a decision somebody makes rather than a side effect.
     *
     * @return iterable<string, array{TestAccount}>
     */
    public static function nonAuthenticatingAccountProvider(): iterable
    {
        foreach (TestAccounts::notAuthenticating() as $account) {
            yield $account->hashFormat . ' / ' . $account->storedUsername => [$account];
        }
    }

    #[DataProvider('nonAuthenticatingAccountProvider')]
    public function testAFormatNoPamLineCoversDoesNotAuthenticate(TestAccount $account): void
    {
        $this->requireFixtures();

        $imap = ImapProbe::connect();

        try {
            self::assertFalse(
                $imap->authenticatePlain($account->storedUsername, $account->password),
                sprintf(
                    'The %s account %s authenticated. That means a pam_mysql line was added which '
                    . 'production does not have — deliberate or not, the two must match.',
                    $account->hashFormat,
                    $account->storedUsername,
                ),
            );
        } finally {
            $imap->close();
        }
    }

    /**
     * Both spellings reach the same account, because `allusers` offers both.
     * Authenticating is not the same as owning a mailbox, though — see
     * {@see AutocreateDisabledTest}.
     */
    public function testTheAddressSpellingAuthenticatesToo(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::bcryptAccount();
        $imap = ImapProbe::connect();

        try {
            self::assertTrue(
                $imap->authenticatePlain($account->address, $account->password),
                'The address form was refused, although allusers carries it.',
            );
        } finally {
            $imap->close();
        }
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Fixtures;

/**
 * The synthetic accounts the Docker environment is seeded with (F5).
 *
 * The authoritative copy of the data is
 * `docker/db/fixtures/20-synthetic-data.sql`; this class is the same list in a
 * form PHP can use. `FixtureCatalogTest` fails if the two drift apart, so
 * neither can be edited alone.
 *
 * M1, M2, M3, A2 and A6 are meant to build on this rather than invent their own
 * accounts: one shared set means a change to the environment breaks the tests
 * that care, instead of leaving each package with a private idea of what is in
 * the database.
 */
final class TestAccounts
{
    /** Accounts here are stored as `local.domain.tld`. */
    public const string DOTTED_DOMAIN = 'example.test';

    /** Accounts here are stored as `local@domain.tld`. In `loginrealms`. */
    public const string FQUN_DOMAIN = 'beispiel.invalid';

    /** A domain with no accounts at all. */
    public const string EMPTY_DOMAIN = 'leer.test';

    /** Cyrus administrator. Named in `imapd.conf: admins`, bcrypt hashed. */
    public const string ADMIN_USER = 'cyrus';
    public const string ADMIN_PASSWORD = 'testenv-cyrus-admin-pw';

    /** webcyradm superuser (`adminuser.type = 0`), DES-crypt hashed. */
    public const string SUPERUSER = 'superadmin';
    public const string SUPERUSER_PASSWORD = 'superadmin-secret-2026';

    /** webcyradm domain master (`type = 1`), scoped to example.test. */
    public const string DOMAIN_MASTER = 'dmaster';
    public const string DOMAIN_MASTER_PASSWORD = 'dmaster-secret-2026';

    /**
     * @return list<TestAccount>
     */
    public static function all(): array
    {
        return [
            // The Definition of Done rests on this one: bcrypt, and it must log
            // in over IMAP and over SMTP-AUTH.
            new TestAccount(
                storedUsername: 'anna.example.test',
                address: 'anna@example.test',
                domain: 'example.test',
                password: 'anna-secret-2026',
                hashFormat: 'bcrypt',
                namingScheme: 'dotted',
                authenticates: true,
            ),
            // The backward path, and equally part of the promise: an account
            // that has never been touched since webcyradm wrote it.
            new TestAccount(
                storedUsername: 'bernd.example.test',
                address: 'bernd@example.test',
                domain: 'example.test',
                password: 'bernd-secret-2026',
                hashFormat: 'descrypt',
                namingScheme: 'dotted',
                authenticates: true,
            ),
            new TestAccount(
                storedUsername: 'clara.example.test',
                address: 'clara@example.test',
                domain: 'example.test',
                password: 'clara-secret-2026',
                hashFormat: 'md5crypt',
                namingScheme: 'dotted',
                authenticates: true,
            ),
            // The second format that exists in production, and the reason for
            // the `crypt=4` line next to `crypt=1`.
            new TestAccount(
                storedUsername: 'dieter.example.test',
                address: 'dieter@example.test',
                domain: 'example.test',
                password: 'dieter-secret-2026',
                hashFormat: 'sha1hex',
                namingScheme: 'dotted',
                authenticates: true,
            ),
            // Readable by the application, not authenticatable through
            // saslauthd: no pam_mysql line covers MySQL PASSWORD().
            new TestAccount(
                storedUsername: 'erika.example.test',
                address: 'erika@example.test',
                domain: 'example.test',
                password: 'erika-secret-2026',
                hashFormat: 'mysql_password',
                namingScheme: 'dotted',
                authenticates: false,
            ),
            // Same again for plaintext.
            new TestAccount(
                storedUsername: 'frank.example.test',
                address: 'frank@example.test',
                domain: 'example.test',
                password: 'frank-secret-2026',
                hashFormat: 'plaintext',
                namingScheme: 'dotted',
                authenticates: false,
            ),
            // A local part with a dot in it. Proof that a stored Cyrus name
            // cannot be split without `domain_name`.
            new TestAccount(
                storedUsername: 'gerd.hoffmann.example.test',
                address: 'gerd.hoffmann@example.test',
                domain: 'example.test',
                password: 'gerd-secret-2026',
                hashFormat: 'bcrypt',
                namingScheme: 'dotted',
                authenticates: true,
            ),
            // A local part that contains the domain name. The `allusers` view
            // reports `news@example.test` for this account, because REPLACE
            // removes every occurrence (architecture 3.3). Not fixed, shown.
            new TestAccount(
                storedUsername: 'news.example.test.example.test',
                address: 'news.example.test@example.test',
                domain: 'example.test',
                password: 'news-secret-2026',
                hashFormat: 'bcrypt',
                namingScheme: 'dotted',
                authenticates: true,
            ),
            new TestAccount(
                storedUsername: 'hans@beispiel.invalid',
                address: 'hans@beispiel.invalid',
                domain: 'beispiel.invalid',
                password: 'hans-secret-2026',
                hashFormat: 'bcrypt',
                namingScheme: 'fqun',
                authenticates: true,
            ),
            new TestAccount(
                storedUsername: 'iris@beispiel.invalid',
                address: 'iris@beispiel.invalid',
                domain: 'beispiel.invalid',
                password: 'iris-secret-2026',
                hashFormat: 'sha512crypt',
                namingScheme: 'fqun',
                authenticates: true,
            ),
        ];
    }

    /**
     * The accounts saslauthd accepts — everything crypt(3) or SHA1 hex.
     *
     * @return list<TestAccount>
     */
    public static function authenticating(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (TestAccount $account): bool => $account->authenticates,
        ));
    }

    /**
     * The accounts saslauthd rejects, because no pam_mysql line reads their
     * format. Not a defect — production has no such line either, and the point
     * of having them is that the limit is visible.
     *
     * @return list<TestAccount>
     */
    public static function notAuthenticating(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (TestAccount $account): bool => !$account->authenticates,
        ));
    }

    public static function byStoredUsername(string $storedUsername): TestAccount
    {
        foreach (self::all() as $account) {
            if ($account->storedUsername === $storedUsername) {
                return $account;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'There is no fixture account "%s". See docker/db/fixtures/20-synthetic-data.sql.',
            $storedUsername,
        ));
    }

    /**
     * The bcrypt account in the dotted scheme — the one the Definition of Done
     * names by shape rather than by name.
     */
    public static function bcryptAccount(): TestAccount
    {
        return self::byStoredUsername('anna.example.test');
    }

    /**
     * The DES-crypt account: the backward path is part of the promise.
     */
    public static function desCryptAccount(): TestAccount
    {
        return self::byStoredUsername('bernd.example.test');
    }
}

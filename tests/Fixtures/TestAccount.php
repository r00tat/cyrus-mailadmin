<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Fixtures;

/**
 * One synthetic account from the test environment.
 *
 * Everything in here is invented — reserved TLDs, made-up names, passwords that
 * are printed next to their own hashes. E-5 treats a production value in a
 * fixture as an escalation case, and a fixture is committed by definition.
 */
final readonly class TestAccount
{
    /**
     * @param string $storedUsername `accountuser.username` — the Cyrus name, in
     *                               whichever spelling the account actually
     *                               uses. Never taken apart (architecture 3.3).
     * @param string $address        the address the account receives at
     * @param string $domain         `accountuser.domain_name` — the only place
     *                               that says where the local part ends
     * @param string $password       the clear-text password, so a test does not
     *                               have to reverse a hash
     * @param string $hashFormat     how `accountuser.password` is stored
     * @param string $namingScheme   `dotted` or `fqun`
     * @param bool $authenticates    whether saslauthd accepts this account.
     *                               False for the two formats no pam_mysql line
     *                               covers — see docker/mail/pam/pam_mysql.tmpl.
     */
    public function __construct(
        public string $storedUsername,
        public string $address,
        public string $domain,
        public string $password,
        public string $hashFormat,
        public string $namingScheme,
        public bool $authenticates,
    ) {
    }

    /**
     * The mailbox name in Cyrus, as `create-mailboxes.pl` created it.
     */
    public function mailbox(): string
    {
        return 'user/' . $this->storedUsername;
    }

    /**
     * A spelling this account must NOT be able to use as a mailbox owner.
     *
     * For a dotted account that is the address form and vice versa. Logging in
     * with it is what produced 33 shadow mailboxes in production (finding
     * S-13); with `autocreate_quota: -1` it has to come to nothing instead.
     */
    public function wrongSchemeUsername(): string
    {
        return $this->namingScheme === 'dotted'
            ? $this->address
            : str_replace('@', '.', $this->storedUsername);
    }
}

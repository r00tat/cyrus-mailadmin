<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use Mailadmin\Tests\Fixtures\TestAccounts;
use PHPUnit\Framework\TestCase;

/**
 * The other half of the Definition of Done: SMTP-AUTH over STARTTLS.
 *
 * Postfix and Cyrus share one saslauthd and one PAM stack, so this does not
 * re-test crypt(3) — it tests that Postfix is wired into the same chain. If IMAP
 * works and this does not, the fault is in `smtpd_sasl_*` or in
 * /etc/pam.d/smtp, and nowhere else.
 */
final class SmtpAuthenticationTest extends TestCase
{
    use RequiresTestEnvironment;

    public function testAuthenticationSucceedsOverStartTls(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::bcryptAccount();
        $smtp = SmtpProbe::connect();

        try {
            $smtp->ehlo();
            $smtp->startTls();
            self::assertTrue($smtp->isEncrypted());

            self::assertSame(
                235,
                $smtp->authPlain($account->storedUsername, $account->password),
                'Postfix refused a correct bcrypt password. Compare with the IMAP test: if that '
                . 'one passes, the fault is in smtpd_sasl_* or /etc/pam.d/smtp.',
            );
        } finally {
            $smtp->close();
        }
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::bcryptAccount();
        $smtp = SmtpProbe::connect();

        try {
            $smtp->ehlo();
            $smtp->startTls();

            self::assertSame(
                535,
                $smtp->authPlain($account->storedUsername, $account->password . '-wrong'),
                'Postfix did not answer 535 to a wrong password.',
            );
        } finally {
            $smtp->close();
        }
    }

    /**
     * A DES-crypt account over SMTP as well. The backward path is not an IMAP
     * speciality — an old account has to keep sending, too.
     */
    public function testADesCryptAccountAuthenticates(): void
    {
        $this->requireFixtures();

        $account = TestAccounts::desCryptAccount();
        $smtp = SmtpProbe::connect();

        try {
            $smtp->ehlo();
            $smtp->startTls();

            self::assertSame(235, $smtp->authPlain($account->storedUsername, $account->password));
        } finally {
            $smtp->close();
        }
    }

    /**
     * `smtpd_tls_auth_only = yes`: no credential before STARTTLS. The SMTP
     * counterpart of `allowplaintext: no` in imapd.conf, and the same finding
     * (S-5).
     */
    public function testAuthIsNotOfferedBeforeStartTls(): void
    {
        $this->requireTestEnvironment();

        $smtp = SmtpProbe::connect();

        try {
            $capabilities = $smtp->ehlo();

            $advertisesAuth = false;

            foreach ($capabilities as $capability) {
                if (stripos($capability, 'AUTH') === 0) {
                    $advertisesAuth = true;
                }
            }

            self::assertFalse(
                $advertisesAuth,
                'Postfix advertises AUTH on an unencrypted connection. smtpd_tls_auth_only must '
                . 'be yes.',
            );

            self::assertContains(
                'STARTTLS',
                $capabilities,
                'The submission port does not offer STARTTLS.',
            );
        } finally {
            $smtp->close();
        }
    }

    /**
     * The `validsenders` view is not decoration: with
     * `reject_authenticated_sender_login_mismatch`, an authenticated user may
     * only put an address into MAIL FROM that the view ties to their login.
     * This is what stops one customer sending as another.
     */
    public function testAnAuthenticatedUserCannotSendAsSomebodyElse(): void
    {
        $this->requireFixtures();

        $anna = TestAccounts::bcryptAccount();
        $bernd = TestAccounts::desCryptAccount();

        $smtp = SmtpProbe::connect();

        try {
            $smtp->ehlo();
            $smtp->startTls();
            $smtp->ehlo();
            self::assertSame(235, $smtp->authPlain($anna->storedUsername, $anna->password));

            // `smtpd_delay_reject` is on by default, so the verdict on the
            // sender arrives at RCPT TO rather than at MAIL FROM. Left at the
            // default deliberately: a test that needs the server reconfigured
            // is testing a server nobody runs.
            [$ownFrom] = $smtp->command(sprintf('MAIL FROM:<%s>', $anna->address));
            self::assertSame(250, $ownFrom);
            [$ownTo] = $smtp->command('RCPT TO:<' . $bernd->address . '>');
            self::assertSame(250, $ownTo, 'Anna may not send as herself.');

            $smtp->command('RSET');

            [$foreignFrom] = $smtp->command(sprintf('MAIL FROM:<%s>', $bernd->address));
            self::assertSame(250, $foreignFrom);
            [$foreignTo] = $smtp->command('RCPT TO:<' . $anna->address . '>');
            self::assertSame(553, $foreignTo, sprintf(
                'Anna was allowed to send as %s. smtpd_sender_login_maps or the validsenders view '
                . 'is not doing its job.',
                $bernd->address,
            ));
        } finally {
            $smtp->close();
        }
    }
}

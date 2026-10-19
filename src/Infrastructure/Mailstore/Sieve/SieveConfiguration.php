<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Sieve;

use Mailadmin\Config\Config;
use Mailadmin\Infrastructure\Mailstore\MailstoreConfiguration;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\TlsMode;

/**
 * Where ManageSieve is — which is *where Cyrus is*, on another port.
 *
 * One variable, `MAILADMIN_MANAGESIEVE_PORT`, and everything else is taken from
 * {@see MailstoreConfiguration}. That is a decision rather than laziness: host,
 * CA bundle, timeouts and administrator are properties of the **server**, and
 * timsieved is the same server as imapd. A second set of variables would let a
 * deployment point Sieve at one machine and IMAP at another, where the two would
 * silently disagree about which account exists — and it would let a deployment
 * name a CA for one and not the other, which is a certificate check that passes
 * by accident.
 *
 * **The TLS mode is not configurable and is always `STARTTLS`.** RFC 5804
 * defines no implicit-TLS port and Cyrus's timsieved offers none, so there is
 * nothing for a second mode to select. The rule that matters is unchanged: no
 * credential before {@see \Mailadmin\Infrastructure\Mailstore\Net\Transport::isEncrypted()}
 * says yes.
 */
final readonly class SieveConfiguration
{
    public const string PORT = 'MAILADMIN_MANAGESIEVE_PORT';

    /** The IANA port for ManageSieve (RFC 5804, section 1.8). */
    public const int DEFAULT_PORT = 4190;

    private function __construct(public Endpoint $endpoint)
    {
    }

    public static function fromConfig(Config $config, MailstoreConfiguration $mailstore): self
    {
        return new self(
            $mailstore->endpoint
                ->withPort($config->intOrDefault(self::PORT, self::DEFAULT_PORT))
                ->withTlsMode(TlsMode::StartTls),
        );
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Mailadmin\Config\Config;
use Mailadmin\Infrastructure\Mailstore\Net\Endpoint;
use Mailadmin\Infrastructure\Mailstore\Net\TlsMode;

/**
 * Where Cyrus is, and who mailadmin is to it — read once at startup (U-11).
 *
 * Modelled on {@see \Mailadmin\Infrastructure\Persistence\DatabaseConfiguration}
 * and bound by the same rule: the password lives in one property of one object
 * and leaves it only through {@see Credentials::secret()}. There is no method
 * here that renders the configuration with the password in it, because the
 * reliable way to keep a password out of a log is to have no code path that
 * produces one.
 *
 * **There is no variable that turns certificate verification off**, and no
 * variable that selects plaintext. `MAILADMIN_IMAP_TLS` chooses *when* TLS
 * starts, not whether — see {@see TlsMode}. A deployment with a private CA names
 * the bundle in `MAILADMIN_IMAP_CA_FILE`; F5 does exactly that.
 */
final readonly class MailstoreConfiguration
{
    public const string HOST = 'MAILADMIN_IMAP_HOST';
    public const string PORT = 'MAILADMIN_IMAP_PORT';
    public const string TLS = 'MAILADMIN_IMAP_TLS';
    public const string CA_FILE = 'MAILADMIN_IMAP_CA_FILE';
    public const string ADMIN_USER = 'MAILADMIN_IMAP_ADMIN_USER';
    public const string ADMIN_PASSWORD = 'MAILADMIN_IMAP_ADMIN_PASSWORD';
    public const string CONNECT_TIMEOUT = 'MAILADMIN_IMAP_CONNECT_TIMEOUT';
    public const string READ_TIMEOUT = 'MAILADMIN_IMAP_READ_TIMEOUT';

    /** IMAPS. The `STARTTLS` port is 143 and has to be given explicitly. */
    public const int DEFAULT_PORT = 993;

    private function __construct(
        public Endpoint $endpoint,
        public Credentials $credentials,
    ) {
    }

    /**
     * @throws ConfigurationInvalid when a required variable is absent or a value
     *                              is unusable — at startup, where an operator is
     *                              still looking, rather than three requests later
     */
    public static function fromConfig(Config $config): self
    {
        if (!$config->has(self::HOST)) {
            throw ConfigurationInvalid::missingHost();
        }

        if (!$config->has(self::ADMIN_USER)) {
            throw ConfigurationInvalid::unusableAdministrator(sprintf(
                '%s is not set. Cyrus needs an account listed in its `admins` line '
                . '(architecture 9.3).',
                self::ADMIN_USER,
            ));
        }

        if (!$config->has(self::ADMIN_PASSWORD)) {
            throw ConfigurationInvalid::missingCredential(self::ADMIN_PASSWORD);
        }

        // Defaulting to IMAPS rather than demanding the variable: the answer to
        // M1's open point 1 is that implicit TLS is the default, and a required
        // variable whose only sensible value is the default is a trap.
        $mode = $config->has(self::TLS)
            ? TlsMode::from($config->enum(self::TLS, TlsMode::values()))
            : TlsMode::Implicit;

        return new self(
            Endpoint::of(
                host: $config->string(self::HOST),
                port: $config->intOrDefault(self::PORT, self::DEFAULT_PORT),
                tlsMode: $mode,
                connectTimeout: self::seconds($config, self::CONNECT_TIMEOUT, Endpoint::DEFAULT_CONNECT_TIMEOUT),
                readTimeout: self::seconds($config, self::READ_TIMEOUT, Endpoint::DEFAULT_READ_TIMEOUT),
                caFile: $config->stringOrDefault(self::CA_FILE, '') ?: null,
            ),
            Credentials::of(
                $config->string(self::ADMIN_USER),
                $config->string(self::ADMIN_PASSWORD),
            ),
        );
    }

    /**
     * Timeouts are whole seconds in the environment. A sub-second timeout on a
     * TLS handshake fails under any load at all, and {@see Endpoint} refuses one
     * anyway — offering fractions would only make that refusal reachable.
     */
    private static function seconds(Config $config, string $key, float $default): float
    {
        return (float) $config->intOrDefault($key, (int) $default);
    }
}

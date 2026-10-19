<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Config\Config;
use SensitiveParameter;

/**
 * Where the database is and who we are to it — read once at startup (U-11).
 *
 * The password lives in exactly one property of one object and leaves it only
 * through {@see password()}, which {@see ConnectionFactory} calls. Everything
 * that describes the connection for a human goes through
 * {@see redactedDsn()}: `CLAUDE.md` forbids a DSN with a password in a log, and
 * the reliable way to keep that promise is to have no code path that produces
 * one.
 *
 * The DSN is required to be a MySQL/MariaDB DSN carrying `charset=utf8mb4`
 * (architecture 10.1). A missing charset is appended rather than rejected —
 * connecting with the server default would mean silent mojibake on a four-byte
 * character, which is worse than a corrected configuration.
 */
final readonly class DatabaseConfiguration
{
    public const string DSN = 'MAILADMIN_DB_DSN';
    public const string USER = 'MAILADMIN_DB_USER';
    public const string PASSWORD = 'MAILADMIN_DB_PASSWORD';
    public const string TIMEOUT = 'MAILADMIN_DB_TIMEOUT';

    /** Seconds to wait for a connection before giving up. */
    public const int DEFAULT_TIMEOUT = 5;

    public const string CHARSET = 'utf8mb4';

    private function __construct(
        public string $dsn,
        public string $user,
        #[SensitiveParameter]
        private string $password,
        public int $timeoutSeconds,
    ) {
    }

    /**
     * @throws PersistenceError when a required variable is absent or the DSN is unusable
     */
    public static function fromConfig(Config $config): self
    {
        if (!$config->has(self::DSN)) {
            throw PersistenceError::configuration(sprintf(
                '%s is not set. Expected a PDO DSN such as '
                . '"mysql:host=<host>;port=3306;dbname=<database>;charset=%s".',
                self::DSN,
                self::CHARSET,
            ));
        }

        if (!$config->has(self::USER)) {
            throw PersistenceError::configuration(sprintf(
                '%s is not set. The application must not reuse the database user of Postfix or '
                . 'pam_mysql (architecture 10.1).',
                self::USER,
            ));
        }

        return self::create(
            dsn: $config->string(self::DSN),
            user: $config->string(self::USER),
            password: $config->stringOrDefault(self::PASSWORD, ''),
            timeoutSeconds: $config->intOrDefault(self::TIMEOUT, self::DEFAULT_TIMEOUT),
        );
    }

    /**
     * @throws PersistenceError when the DSN names another driver or has no database
     */
    public static function create(
        string $dsn,
        string $user,
        #[SensitiveParameter]
        string $password = '',
        int $timeoutSeconds = self::DEFAULT_TIMEOUT,
    ): self {
        $dsn = trim($dsn);

        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            throw PersistenceError::configuration(sprintf(
                '%s must be a MySQL/MariaDB DSN starting with "mysql:". The application speaks to '
                . 'the same database as Postfix and pam_mysql; there is no second dialect.',
                self::DSN,
            ));
        }

        if ($timeoutSeconds < 1) {
            throw PersistenceError::configuration(sprintf(
                '%s must be at least 1 second.',
                self::TIMEOUT,
            ));
        }

        return new self(self::withCharset($dsn), $user, $password, $timeoutSeconds);
    }

    /**
     * Handed to PDO and to nothing else.
     */
    public function password(): string
    {
        return $this->password;
    }

    /**
     * The DSN as it may appear in a log or an exception message.
     *
     * PDO DSNs do not usually carry credentials, but nothing stops an operator
     * from putting `password=` into one, and a `unix_socket` path is closer to
     * infrastructure detail than to something a log needs. Both are replaced.
     */
    public function redactedDsn(): string
    {
        return (string) preg_replace(
            '/\b(password|passwd|pwd)=[^;]*/i',
            '$1=<redacted>',
            $this->dsn,
        );
    }

    /**
     * The database name from the DSN, if it names one.
     *
     * Used to scope the migration lock: `GET_LOCK` is server-wide, so two
     * installations on one server would otherwise block each other.
     */
    public function databaseName(): ?string
    {
        if (preg_match('/\bdbname=([^;]+)/i', $this->dsn, $matches) !== 1) {
            return null;
        }

        $name = trim($matches[1]);

        return $name === '' ? null : $name;
    }

    private static function withCharset(string $dsn): string
    {
        if (preg_match('/\bcharset=/i', $dsn) === 1) {
            return (string) preg_replace('/\bcharset=[^;]*/i', 'charset=' . self::CHARSET, $dsn);
        }

        return rtrim($dsn, ';') . ';charset=' . self::CHARSET;
    }
}

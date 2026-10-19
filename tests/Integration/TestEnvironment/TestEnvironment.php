<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use PDO;
use RuntimeException;

/**
 * Where the Docker environment is, and whether it is there at all.
 *
 * Every integration test asks this class first. Without the environment the
 * tests skip rather than fail: `composer test` has to stay usable on a machine
 * where Docker is not running, or people stop running it.
 *
 * The variables come from `docker/compose.yaml` when a test runs inside the app
 * container (`bin/test-env test`), and from the host defaults below when it runs
 * on the host against the published ports.
 */
final class TestEnvironment
{
    /** Host-side defaults, matching the published ports in docker/compose.yaml. */
    private const string DEFAULT_HOST = '127.0.0.1';
    private const int DEFAULT_IMAPS_PORT = 10993;
    private const int DEFAULT_IMAP_PORT = 10143;
    private const int DEFAULT_SUBMISSION_PORT = 10587;
    private const int DEFAULT_MANAGESIEVE_PORT = 14190;

    /**
     * The name on the server certificate. Verification is on everywhere
     * (architecture 9.2), so this is what the certificate is checked against
     * even when the connection is dialled by IP.
     */
    public const string CERTIFICATE_NAME = 'mail.example.test';

    public static function mailHost(): string
    {
        return self::env('MAILADMIN_TEST_MAIL_HOST', self::DEFAULT_HOST);
    }

    public static function imapsPort(): int
    {
        return self::intEnv('MAILADMIN_TEST_IMAPS_PORT', self::DEFAULT_IMAPS_PORT);
    }

    public static function imapPort(): int
    {
        return self::intEnv('MAILADMIN_TEST_IMAP_PORT', self::DEFAULT_IMAP_PORT);
    }

    public static function submissionPort(): int
    {
        return self::intEnv('MAILADMIN_TEST_SUBMISSION_PORT', self::DEFAULT_SUBMISSION_PORT);
    }

    public static function manageSievePort(): int
    {
        return self::intEnv('MAILADMIN_TEST_MANAGESIEVE_PORT', self::DEFAULT_MANAGESIEVE_PORT);
    }

    /**
     * The Apache in front of the application — the one layer the contract
     * tests never pass through, because they call the kernel directly.
     */
    public static function webBaseUrl(): string
    {
        return rtrim(self::env('MAILADMIN_TEST_WEB_URL', 'http://' . self::DEFAULT_HOST . ':18080'), '/');
    }

    /**
     * The test CA, for a client that does not use the system trust store.
     *
     * Inside the app container the CA is installed system-wide and this is not
     * needed; on the host it is, because nobody should have to add a test CA to
     * their laptop.
     */
    public static function caFile(): ?string
    {
        $configured = self::env('MAILADMIN_TEST_CA_FILE', '');

        if ($configured !== '' && is_readable($configured)) {
            return $configured;
        }

        $local = dirname(__DIR__, 3) . '/docker/certs/out/ca.crt';

        return is_readable($local) ? $local : null;
    }

    public static function adminUser(): string
    {
        return self::env('MAILADMIN_TEST_IMAP_ADMIN_USER', 'cyrus');
    }

    public static function adminPassword(): string
    {
        return self::env('MAILADMIN_TEST_IMAP_ADMIN_PASSWORD', 'testenv-cyrus-admin-pw');
    }

    public static function databaseDsn(): string
    {
        return self::env(
            'MAILADMIN_TEST_DB_DSN',
            'mysql:host=127.0.0.1;port=13306;dbname=mail;charset=utf8mb4',
        );
    }

    public static function databaseUser(): string
    {
        return self::env('MAILADMIN_TEST_DB_USER', 'mailadmin');
    }

    public static function databasePassword(): string
    {
        return self::env('MAILADMIN_TEST_DB_PASSWORD', 'testenv-app-pw');
    }

    /**
     * A connection with the same PDO attributes the application uses
     * (architecture 10.1, CLAUDE.md rule two).
     *
     * Deliberately not routed through F3's ConnectionFactory: an integration
     * test for the environment must not fail because the class it uses to reach
     * the database is itself broken.
     */
    public static function connection(): PDO
    {
        $pdo = new PDO(
            self::databaseDsn(),
            self::databaseUser(),
            self::databasePassword(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ],
        );

        // U-14: UTC on the connection, not just in PHP.
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    /**
     * Why the environment is unusable, or null if it is usable.
     *
     * Returns a reason rather than a boolean so the skip message tells the
     * reader which half is missing.
     */
    public static function unavailableReason(): ?string
    {
        if (!self::portAnswers(self::mailHost(), self::imapsPort())) {
            return sprintf(
                'The Docker test environment is not running: nothing answers on %s:%d. Start it '
                . 'with `bin/test-env up`.',
                self::mailHost(),
                self::imapsPort(),
            );
        }

        try {
            self::connection();
        } catch (\PDOException $exception) {
            return 'The test database is not reachable: ' . $exception->getMessage();
        }

        return null;
    }

    /**
     * True once the fixtures are in place. A test that needs data and finds an
     * empty schema should say so, not fail on a missing row.
     */
    public static function fixturesLoaded(): bool
    {
        try {
            $statement = self::connection()->query(
                "SELECT COUNT(*) FROM accountuser WHERE username = 'anna.example.test'",
            );
        } catch (\PDOException) {
            return false;
        }

        return $statement !== false && (int) $statement->fetchColumn() === 1;
    }

    private static function portAnswers(string $host, int $port, float $timeout = 2.0): bool
    {
        $handle = @fsockopen($host, $port, $errno, $error, $timeout);

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    private static function intEnv(string $name, int $default): int
    {
        $value = self::env($name, (string) $default);

        if (!ctype_digit($value)) {
            throw new RuntimeException(sprintf('%s must be a port number, got "%s".', $name, $value));
        }

        return (int) $value;
    }
}

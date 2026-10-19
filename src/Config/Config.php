<?php

declare(strict_types=1);

namespace Mailadmin\Config;

/**
 * The only place in the application that reads the process environment (U-11).
 *
 * Nothing else calls `getenv()`, `$_ENV` or `$_SERVER` for configuration. That
 * keeps two promises checkable in one file: configuration never comes from a
 * file inside the document root, and a missing mandatory value stops the
 * application at startup instead of surfacing as a strange error later.
 *
 * An empty or whitespace-only value counts as *not set*. Deployment tooling
 * frequently exports empty variables, and treating `MAILADMIN_LOG_TARGET=` as a
 * log target named "" would be worse than falling back to the default.
 */
final class Config
{
    public const string ENV = 'MAILADMIN_ENV';
    public const string LOG_TARGET = 'MAILADMIN_LOG_TARGET';
    public const string LOG_LEVEL = 'MAILADMIN_LOG_LEVEL';
    public const string CACHE_DIR = 'MAILADMIN_CACHE_DIR';
    public const string BASE_PATH = 'MAILADMIN_BASE_PATH';

    /**
     * @param array<string, string> $values already-normalised values, empty ones removed
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Reads the process environment.
     *
     * `$_ENV` is preferred over `getenv()` because phpdotenv populates it in
     * development (U-11) and because `getenv()` is not thread-safe in every
     * SAPI. `getenv()` is the fallback for setups where `variables_order` does
     * not include `E`.
     */
    public static function fromEnvironment(): self
    {
        $raw = $_ENV;

        /** @var array<string, string>|false $fromGetenv */
        $fromGetenv = getenv();
        if ($fromGetenv !== false) {
            $raw += $fromGetenv;
        }

        return self::fromArray($raw);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $normalised = [];

        foreach ($values as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }

            $normalised[$key] = $trimmed;
        }

        return new self($normalised);
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * @throws ConfigurationError if the variable is absent
     */
    public function string(string $key): string
    {
        return $this->values[$key] ?? throw ConfigurationError::missing($key);
    }

    public function stringOrDefault(string $key, string $default): string
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @param list<string> $allowed
     *
     * @throws ConfigurationError if the variable is absent or holds another value
     */
    public function enum(string $key, array $allowed): string
    {
        $value = $this->string($key);

        if (!in_array($value, $allowed, true)) {
            throw ConfigurationError::notAllowed($key, $allowed);
        }

        return $value;
    }

    /**
     * @throws ConfigurationError if the variable is absent or not an integer
     */
    public function int(string $key): int
    {
        $value = $this->string($key);

        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw ConfigurationError::notAnInteger($key);
        }

        return (int) $value;
    }

    public function intOrDefault(string $key, int $default): int
    {
        return $this->has($key) ? $this->int($key) : $default;
    }

    /**
     * @throws ConfigurationError if the variable is absent or not a boolean
     */
    public function bool(string $key): bool
    {
        $value = strtolower($this->string($key));

        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw ConfigurationError::notABoolean($key),
        };
    }

    public function boolOrDefault(string $key, bool $default): bool
    {
        return $this->has($key) ? $this->bool($key) : $default;
    }

    /**
     * @throws ConfigurationError if MAILADMIN_ENV is absent or unknown
     */
    public function environment(): Environment
    {
        return Environment::from($this->enum(self::ENV, Environment::values()));
    }

    /**
     * `stderr` or an absolute file path (U-12).
     */
    public function logTarget(): string
    {
        return $this->stringOrDefault(self::LOG_TARGET, 'stderr');
    }

    public function logLevel(): string
    {
        return $this->stringOrDefault(self::LOG_LEVEL, 'info');
    }

    /**
     * Base path when the application is not mounted at the web root.
     *
     * Normalised to either an empty string or a leading slash without a
     * trailing one, so that concatenation never produces a double slash.
     */
    public function basePath(): string
    {
        $value = trim($this->stringOrDefault(self::BASE_PATH, ''), '/');

        return $value === '' ? '' : '/' . $value;
    }

    /**
     * @param string $default absolute path used when MAILADMIN_CACHE_DIR is unset
     */
    public function cacheDirectory(string $default): string
    {
        return rtrim($this->stringOrDefault(self::CACHE_DIR, $default), '/');
    }
}

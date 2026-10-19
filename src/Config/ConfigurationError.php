<?php

declare(strict_types=1);

namespace Mailadmin\Config;

use RuntimeException;

/**
 * Thrown while the application starts up, before any request is served.
 *
 * The message is written for the operator and appears on stderr or in the
 * process log — never in an HTTP response. It must therefore name the variable
 * or path that is wrong, but must never contain a configured secret value.
 */
final class ConfigurationError extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self(sprintf(
            'Required environment variable %s is not set. See .env.example for the expected values.',
            $key,
        ));
    }

    public static function notAnInteger(string $key): self
    {
        return new self(sprintf('Environment variable %s must be an integer.', $key));
    }

    public static function notABoolean(string $key): self
    {
        return new self(sprintf(
            'Environment variable %s must be a boolean (1/0, true/false, yes/no, on/off).',
            $key,
        ));
    }

    /**
     * @param list<string> $allowed
     */
    public static function notAllowed(string $key, array $allowed): self
    {
        return new self(sprintf(
            'Environment variable %s must be one of: %s.',
            $key,
            implode(', ', $allowed),
        ));
    }

    public static function because(string $message): self
    {
        return new self($message);
    }
}

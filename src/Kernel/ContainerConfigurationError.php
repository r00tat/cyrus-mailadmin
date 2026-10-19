<?php

declare(strict_types=1);

namespace Mailadmin\Kernel;

use RuntimeException;

/**
 * Raised while the container is assembled — that is, before any request is
 * served. Never seen by a client.
 */
final class ContainerConfigurationError extends RuntimeException
{
    public static function directoryMissing(string $directory): self
    {
        return new self(sprintf('Container definition directory %s does not exist.', $directory));
    }

    public static function notAnArray(string $file): self
    {
        return new self(sprintf(
            'Container definition file %s must return an array of definitions.',
            $file,
        ));
    }

    public static function nonStringKey(string $file, int|string $key): self
    {
        return new self(sprintf(
            'Container definition file %s uses the non-string key %s. '
            . 'Every definition is keyed by a class name, interface name or dotted identifier.',
            $file,
            (string) $key,
        ));
    }

    public static function duplicateKey(string $key, string $firstFile, string $secondFile): self
    {
        return new self(sprintf(
            'Duplicate container definition "%s": declared in %s and again in %s. '
            . 'Each package owns exactly one file under config/di/ (U-5); '
            . 'move the binding to whichever package owns it.',
            $key,
            $firstFile,
            $secondFile,
        ));
    }
}

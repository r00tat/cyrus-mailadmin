<?php

declare(strict_types=1);

namespace Mailadmin\Runtime;

use Mailadmin\Config\Config;
use Mailadmin\Config\ConfigurationError;

/**
 * The directory that holds build artefacts: the bundled specification, the
 * compiled route table and their checksum (U-8, U-13).
 *
 * U-13 settles what happens when it is not usable: **startup fails**, in every
 * environment. Falling back to parsing YAML per request would turn a
 * configuration mistake into a load problem that only shows up in production.
 *
 * Creating the directory is a build step, not a request-time side effect
 * ({@see \Mailadmin\Runtime\CacheDirectory::ensureUsable()} with
 * `$mayCreate = false`). Outside production it is created on demand, because a
 * fresh checkout should not need a manual `mkdir`.
 */
final class CacheDirectory
{
    private const int MODE = 0o750;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function file(string $name): string
    {
        return $this->path . '/' . ltrim($name, '/');
    }

    /**
     * @throws ConfigurationError if the directory is missing, is not a directory or is not writable
     */
    public function ensureUsable(bool $mayCreate): void
    {
        if (!file_exists($this->path)) {
            if (!$mayCreate) {
                throw ConfigurationError::because(sprintf(
                    'Cache directory %s does not exist. It holds build artefacts and is created by '
                    . '"composer build"; the deployment must ship it. Set %s to move it elsewhere.',
                    $this->path,
                    Config::CACHE_DIR,
                ));
            }

            if (!@mkdir($this->path, self::MODE, true) && !is_dir($this->path)) {
                throw ConfigurationError::because(sprintf(
                    'Cache directory %s could not be created.',
                    $this->path,
                ));
            }
        }

        if (!is_dir($this->path)) {
            throw ConfigurationError::because(sprintf(
                'Cache path %s exists but is not a directory.',
                $this->path,
            ));
        }

        if (!is_writable($this->path)) {
            throw ConfigurationError::because(sprintf(
                'Cache directory %s is not writable by the web server user.',
                $this->path,
            ));
        }
    }
}

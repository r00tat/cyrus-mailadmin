<?php

declare(strict_types=1);

namespace Mailadmin\Kernel;

/**
 * Merges the per-package container definition files (U-5).
 *
 * Every package owns exactly one file in `config/di/`, each returning an array.
 * They are loaded in alphabetical order and merged. **A duplicate key is a fatal
 * error, not a silent overwrite** — with twenty packages contributing bindings,
 * a last-one-wins merge would mean the wiring depends on file names, and
 * whichever package lost would fail somewhere far away from the cause.
 */
final class DefinitionLoader
{
    /**
     * @return array<string, mixed>
     *
     * @throws ContainerConfigurationError
     */
    public static function load(string $directory): array
    {
        if (!is_dir($directory)) {
            throw ContainerConfigurationError::directoryMissing($directory);
        }

        $files = glob(rtrim($directory, '/') . '/*.php');
        if ($files === false) {
            throw ContainerConfigurationError::directoryMissing($directory);
        }

        sort($files, SORT_STRING);

        $definitions = [];

        /** @var array<string, string> $declaredIn */
        $declaredIn = [];

        foreach ($files as $file) {
            $name = basename($file);

            /** @var mixed $contents */
            $contents = require $file;

            if (!is_array($contents)) {
                throw ContainerConfigurationError::notAnArray($name);
            }

            /** @var mixed $definition */
            foreach ($contents as $key => $definition) {
                if (!is_string($key)) {
                    throw ContainerConfigurationError::nonStringKey($name, $key);
                }

                if (isset($declaredIn[$key])) {
                    throw ContainerConfigurationError::duplicateKey($key, $declaredIn[$key], $name);
                }

                $declaredIn[$key] = $name;
                $definitions[$key] = $definition;
            }
        }

        return $definitions;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Runtime;

/**
 * Absolute paths inside the installation.
 *
 * Only `public/` is reachable from the web root; everything this class points at
 * lies above it (architecture, section 3).
 */
final readonly class Paths
{
    public function __construct(public string $root)
    {
    }

    public static function detect(): self
    {
        // src/Runtime/Paths.php -> src/Runtime -> src -> project root
        return new self(dirname(__DIR__, 2));
    }

    public function join(string ...$segments): string
    {
        return $this->root . '/' . implode('/', array_map(
            static fn (string $segment): string => trim($segment, '/'),
            $segments,
        ));
    }

    public function containerDefinitions(): string
    {
        return $this->join('config', 'di');
    }

    public function defaultCacheDirectory(): string
    {
        return $this->join('var', 'cache');
    }
}

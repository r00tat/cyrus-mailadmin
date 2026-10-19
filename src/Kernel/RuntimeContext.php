<?php

declare(strict_types=1);

namespace Mailadmin\Kernel;

use Mailadmin\Config\Config;
use Mailadmin\Runtime\CacheDirectory;
use Mailadmin\Runtime\Paths;

/**
 * What is known before the container exists: where the installation lives, what
 * the environment says, and where build artefacts go.
 *
 * These three cannot come out of the container, because building the container
 * already needs them.
 */
final readonly class RuntimeContext
{
    public function __construct(
        public Paths $paths,
        public Config $config,
        public CacheDirectory $cache,
    ) {
    }
}

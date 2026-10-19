<?php

declare(strict_types=1);

/**
 * Build step: create and verify the cache directory (U-8, U-13).
 *
 * First entry of the `build` composer script. It deliberately does *not* go
 * through `config/bootstrap.php`, for two reasons: that path requires the
 * directory to already exist in production — which is what this command is for —
 * and it requires `MAILADMIN_ENV`, which a fresh `composer install` does not have.
 *
 * Later packages append their own steps to `build`: F2 the bundled specification
 * and the compiled route table with its checksum.
 */

use Mailadmin\Config\Config;
use Mailadmin\Config\ConfigurationError;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $context = Bootstrap::readEnvironment(Paths::detect());
    $context->cache->ensureUsable(mayCreate: true);
} catch (ConfigurationError $error) {
    fwrite(STDERR, 'Configuration error: ' . $error->getMessage() . PHP_EOL);

    exit(1);
}

fwrite(STDOUT, sprintf(
    'Cache directory ready: %s (%s=%s)%s',
    $context->cache->path(),
    Config::ENV,
    $context->config->stringOrDefault(Config::ENV, '<unset>'),
    PHP_EOL,
));

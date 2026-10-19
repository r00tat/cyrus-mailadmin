<?php

declare(strict_types=1);

/**
 * The single startup path, used by `public/index.php` and by every command under
 * `bin/`. Returns the built container.
 *
 * Nothing here is reachable from the web root — only `public/` is (architecture,
 * section 3). Configuration comes from the process environment; there is no
 * configuration file for a web server to hand out, which is the lesson from the
 * old system's credentials in the document root (S-4).
 */

use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;
use Psr\Container\ContainerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$paths = Paths::detect();
$context = Bootstrap::prepare($paths);

// U-13: a missing or unwritable cache directory stops startup. Outside production
// it is created on demand — a fresh checkout should not need a manual mkdir —
// but in production the artefacts are shipped by the deployment (U-8) and their
// absence means the deployment is incomplete.
$context->cache->ensureUsable(mayCreate: !$context->config->environment()->isProduction());

/** @var ContainerInterface $container */
$container = Bootstrap::container($context);

return $container;

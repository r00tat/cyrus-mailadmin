<?php

declare(strict_types=1);

namespace Mailadmin\Kernel;

use DI\ContainerBuilder;
use Mailadmin\Config\Config;
use Mailadmin\Config\DotenvLoader;
use Mailadmin\Runtime\CacheDirectory;
use Mailadmin\Runtime\Paths;
use Psr\Container\ContainerInterface;

/**
 * The startup sequence, shared by the front controller and every CLI command.
 *
 * Split in two on purpose. {@see self::prepare()} produces the environment and
 * may be used by a build command that has to *create* the cache directory it
 * would otherwise be required to find; {@see self::container()} needs that
 * environment to exist.
 */
final class Bootstrap
{
    private function __construct()
    {
    }

    /**
     * Reads the environment without judging it.
     *
     * Used by build steps, which must work on a machine where the runtime
     * environment is not configured yet — `composer install` on a fresh clone
     * has no `MAILADMIN_ENV`, and refusing to prepare the cache directory there
     * would make the install itself fail.
     */
    public static function readEnvironment(Paths $paths): RuntimeContext
    {
        // U-14: one timezone for the whole system. Set before anything can read a
        // clock, so that no value is ever written in local time.
        date_default_timezone_set('UTC');

        DotenvLoader::load($paths->root);

        $config = Config::fromEnvironment();

        return new RuntimeContext(
            paths: $paths,
            config: $config,
            cache: new CacheDirectory($config->cacheDirectory($paths->defaultCacheDirectory())),
        );
    }

    /**
     * {@see self::readEnvironment()} plus the check that `MAILADMIN_ENV` is
     * present and known. This is the path a request and every operational command
     * takes: everything downstream branches on the environment, so a missing or
     * misspelled value must stop startup rather than surface halfway through a
     * request.
     *
     * The cache directory is deliberately *not* verified here — the caller
     * decides how strict to be, see {@see CacheDirectory::ensureUsable()}.
     *
     * @throws \Mailadmin\Config\ConfigurationError
     */
    public static function prepare(Paths $paths): RuntimeContext
    {
        $context = self::readEnvironment($paths);
        $context->config->environment();

        return $context;
    }

    /**
     * @param array<string, mixed> $overrides definitions applied **after** the
     *                                        package files, so that a test can put a
     *                                        double in front of a port. Empty in
     *                                        production, and there is no
     *                                        configuration that fills it — a
     *                                        container that could be redirected by
     *                                        an environment variable would be one
     *                                        variable away from authenticating
     *                                        against nothing (see config/di/auth.php)
     *
     * @throws ContainerConfigurationError
     */
    public static function container(RuntimeContext $context, array $overrides = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        // Attribute-based injection stays off: a constructor is the place where a
        // dependency is visible, and the ActorContext rule (CLAUDE.md) relies on
        // parameters being explicit rather than discovered.
        $builder->useAttributes(false);

        $builder->addDefinitions([
            Paths::class => $context->paths,
            Config::class => $context->config,
            CacheDirectory::class => $context->cache,
            RuntimeContext::class => $context,
        ]);

        $builder->addDefinitions(DefinitionLoader::load($context->paths->containerDefinitions()));

        if ($overrides !== []) {
            // Last one wins in PHP-DI, which is what makes this an override
            // rather than the duplicate-key error DefinitionLoader raises
            // between package files.
            $builder->addDefinitions($overrides);
        }

        return $builder->build();
    }
}

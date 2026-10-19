<?php

declare(strict_types=1);

namespace Mailadmin\Config;

use Dotenv\Dotenv;

/**
 * Loads a `.env` file — in development only (U-11).
 *
 * `vlucas/phpdotenv` is a `require-dev` dependency, so in a production install
 * (`composer install --no-dev`) the class below simply is not there. All three
 * guards must hold before anything is read:
 *
 *   1. MAILADMIN_ENV is not `prod`
 *   2. the package is installed
 *   3. a `.env` exists
 *
 * The environment check reads `getenv()` directly and not {@see Config},
 * because Config is what we are about to build.
 */
final class DotenvLoader
{
    private function __construct()
    {
    }

    /**
     * @return bool whether a file was loaded
     */
    public static function load(string $projectRoot): bool
    {
        $environment = getenv(Config::ENV);
        if (is_string($environment) && trim($environment) === Environment::Production->value) {
            return false;
        }

        if (!class_exists(Dotenv::class)) {
            return false;
        }

        if (!is_file($projectRoot . '/.env')) {
            return false;
        }

        // createImmutable: an already exported variable wins over the file, so a
        // deliberate `MAILADMIN_ENV=test vendor/bin/phpunit` is not overwritten.
        Dotenv::createImmutable($projectRoot)->safeLoad();

        return true;
    }
}

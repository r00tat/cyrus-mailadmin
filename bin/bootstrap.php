<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the commands under `bin/`. Returns the container.
 *
 * A CLI command starts like this:
 *
 *     #!/usr/bin/env php
 *     <?php
 *     declare(strict_types=1);
 *
 *     $container = require __DIR__ . '/bootstrap.php';
 *     $thing = $container->get(Something::class);
 *
 * Files here carry the `.php` suffix so that PHPStan and PHP-CS-Fixer pick them
 * up with their default file patterns; the composer scripts invoke them through
 * `@php bin/<name>.php`.
 *
 * UTC is set by {@see \Mailadmin\Kernel\Bootstrap::prepare()}, which
 * `config/bootstrap.php` calls — U-14 applies to CLI commands exactly as it does
 * to requests, since a migration or a repair run writes timestamps too.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit(1);
}

return require dirname(__DIR__) . '/config/bootstrap.php';

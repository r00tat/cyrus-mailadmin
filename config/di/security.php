<?php

declare(strict_types=1);

/**
 * Container definitions for S1 — password handling.
 *
 * One file per package (U-5). Only what autowiring cannot resolve is here: the
 * block list behind its interface, and the two values that come from the
 * environment.
 *
 * `PasswordHasher`, `PasswordPolicy` and `PasswordGenerator` are not listed —
 * PHP-DI builds each of them from its constructor signature once the three
 * entries below exist.
 *
 * S2 adds `config/di/auth.php` for the token issuer and the rate limiter; they
 * are not part of this file.
 */

use Mailadmin\Config\Config;
use Mailadmin\Domain\Password\CommonPasswordList;
use Mailadmin\Infrastructure\Security\FileCommonPasswordList;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Runtime\Paths;

return [
    // ------------------------------------------------------------------
    // Cost parameters (U-21 leaves them open; PasswordHashingOptions explains
    // the numbers). The algorithm itself is NOT configurable: it comes from
    // PasswordSubject, because Argon2id in `accountuser` would break every IMAP
    // and SMTP login (CLAUDE.md, E-3).
    // ------------------------------------------------------------------
    PasswordHashingOptions::class => static fn (Config $config): PasswordHashingOptions
        => PasswordHashingOptions::fromConfig($config),

    // ------------------------------------------------------------------
    // The block list of common passwords (U-21). A data file, not a package
    // (E-12); `resources/` is denied over HTTP by its own .htaccess (U-25).
    // ------------------------------------------------------------------
    CommonPasswordList::class => static fn (Paths $paths): FileCommonPasswordList
        => new FileCommonPasswordList($paths->join('resources', 'common-passwords.txt')),
];

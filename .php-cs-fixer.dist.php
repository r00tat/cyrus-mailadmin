<?php

declare(strict_types=1);

/**
 * Coding style: @PSR12 (U-16), plus three additions that are checks rather than
 * taste:
 *
 *  - declare_strict_types — the type declarations elsewhere in this code base are
 *    only worth anything with strict types on.
 *  - no_unused_imports / ordered_imports — keeps the import block a readable list
 *    of what a class actually touches, and keeps diffs small when a package adds
 *    one.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/bin',
        __DIR__ . '/config',
        __DIR__ . '/public',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/cache/php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ]);

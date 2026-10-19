<?php

declare(strict_types=1);

/**
 * Build step: merge the contract files into one document and check it (U-3, U-8).
 *
 * Writes `var/cache/openapi.bundled.yaml` and `var/cache/openapi.bundled.json`.
 * Everything downstream — request validation, route compilation, the TypeScript
 * types in W1, the contract tests — reads the bundle, never the sources. That is
 * what keeps "one contract in several files" from becoming "several contracts".
 *
 * Run through `composer build`. It exits non-zero on the first problem and says
 * which file caused it.
 */

use Mailadmin\Http\Routing\ContractBuild;
use Mailadmin\Http\Routing\ContractBuildError;
use Mailadmin\Http\Routing\ContractLinter;
use Mailadmin\Http\Routing\SpecificationBundler;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$paths = Paths::detect();

try {
    // Deliberately not config/bootstrap.php: a fresh `composer install` has no
    // MAILADMIN_ENV, and the build has to work there too (see bin/prepare-cache.php).
    $context = Bootstrap::readEnvironment($paths);
    $context->cache->ensureUsable(mayCreate: true);

    $bundler = new SpecificationBundler($paths->join('openapi'));
    $document = $bundler->bundle();

    ContractLinter::assertValid($document);

    $yaml = SpecificationBundler::toYaml($document);
    $json = SpecificationBundler::toJson($document);

    foreach ([ContractBuild::BUNDLED_YAML => $yaml, ContractBuild::BUNDLED_JSON => $json] as $name => $contents) {
        if (file_put_contents($context->cache->file($name), $contents) === false) {
            throw ContractBuildError::because(sprintf('Could not write %s.', $context->cache->file($name)));
        }
    }

    /** @var array<string, mixed> $paths_ */
    $paths_ = is_array($document['paths'] ?? null) ? $document['paths'] : [];

    fwrite(STDOUT, sprintf(
        'Bundled %d contract files into %s (%d paths, sha256 %s).%s',
        count($bundler->sourceFiles()),
        $context->cache->file(ContractBuild::BUNDLED_YAML),
        count($paths_),
        substr(hash('sha256', $yaml), 0, 12),
        PHP_EOL,
    ));
} catch (\Throwable $error) {
    fwrite(STDERR, 'Contract bundling failed: ' . $error->getMessage() . PHP_EOL);

    exit(1);
}

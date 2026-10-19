<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Mailadmin\Runtime\CacheDirectory;

/**
 * Produces the build artefacts and hands them out at runtime (U-8, U-13).
 *
 * Three files land in `var/cache/`:
 *
 *   openapi.bundled.yaml   the merged contract — the artefact humans and
 *                          openapi-typescript (W1) read, and the one the
 *                          checksum is taken over
 *   openapi.bundled.json   the same document as JSON, because starting a
 *                          request with json_decode instead of a YAML parse is
 *                          the difference the architecture asks for when it
 *                          says the parsed specification must be cached
 *   routes.php             the compiled route table, var_exported so that
 *                          opcache keeps it resident
 *
 * At startup the recorded checksum is compared against the bundle actually on
 * disk. In development a mismatch — or an edited source file — rebuilds. Outside
 * development it throws {@see StaleBuildError}, which the dispatcher turns into
 * `503` with `migration.build_stale`: recompiling under load has the same
 * concurrency problem as migrating under load, and a deployment that skipped
 * `composer build` should be loud.
 */
final class ContractBuild implements ContractArtefacts
{
    public const string BUNDLED_YAML = 'openapi.bundled.yaml';
    public const string BUNDLED_JSON = 'openapi.bundled.json';
    public const string ROUTE_TABLE = 'routes.php';

    private ?RouteTable $table = null;

    private ?OpenApi $specification = null;

    public function __construct(
        private readonly SpecificationBundler $bundler,
        private readonly RouteCompiler $compiler,
        private readonly CacheDirectory $cache,
        private readonly bool $mayRebuild,
    ) {
    }

    /**
     * Bundles, validates and compiles. Called by `composer build` and, in
     * development, by {@see self::routeTable()} when the artefacts have drifted.
     *
     * @throws ContractBuildError
     */
    public function build(): RouteTable
    {
        $document = $this->bundler->bundle();

        ContractLinter::assertValid($document);

        $yaml = SpecificationBundler::toYaml($document);
        $json = SpecificationBundler::toJson($document);

        $table = $this->compiler->compile(
            $document,
            specChecksum: hash('sha256', $yaml),
            sourceChecksum: $this->bundler->sourceFingerprint(),
        );

        $this->write(self::BUNDLED_YAML, $yaml);
        $this->write(self::BUNDLED_JSON, $json);
        $this->write(self::ROUTE_TABLE, $table->toPhpSource());

        $this->table = $table;
        $this->specification = null;

        return $table;
    }

    public function routeTable(): RouteTable
    {
        if ($this->table !== null) {
            return $this->table;
        }

        return $this->table = $this->loadRouteTable();
    }

    public function specification(): OpenApi
    {
        if ($this->specification !== null) {
            return $this->specification;
        }

        // Goes through routeTable() first so that the freshness check (and, in
        // development, the rebuild) has happened before the document is parsed.
        $this->routeTable();

        return $this->specification = Reader::readFromJsonFile($this->cache->file(self::BUNDLED_JSON));
    }

    public function bundledJson(): string
    {
        $this->routeTable();

        $contents = file_get_contents($this->cache->file(self::BUNDLED_JSON));

        if ($contents === false) {
            throw StaleBuildError::unreadable(self::BUNDLED_JSON);
        }

        return $contents;
    }

    private function loadRouteTable(): RouteTable
    {
        $routeFile = $this->cache->file(self::ROUTE_TABLE);
        $bundleFile = $this->cache->file(self::BUNDLED_YAML);

        if (!is_file($routeFile) || !is_file($bundleFile) || !is_file($this->cache->file(self::BUNDLED_JSON))) {
            return $this->rebuildOr(static fn (): StaleBuildError => StaleBuildError::missing(self::ROUTE_TABLE));
        }

        /** @var mixed $data */
        $data = require $routeFile;

        if (!is_array($data)) {
            return $this->rebuildOr(static fn (): StaleBuildError => StaleBuildError::unreadable(self::ROUTE_TABLE));
        }

        /** @var array<string, mixed> $data */
        $table = RouteTable::fromArray($data);

        $bundleChecksum = hash_file('sha256', $bundleFile);

        if ($bundleChecksum !== $table->specChecksum) {
            return $this->rebuildOr(
                static fn (): StaleBuildError => StaleBuildError::checksumMismatch(self::BUNDLED_YAML),
            );
        }

        // Only development asks the second question: are the *sources* still the
        // ones this bundle was made from? In production the sources may not even
        // be deployed, and hashing them per request would be pointless work.
        if ($this->mayRebuild && $this->bundler->sourceFingerprint() !== $table->sourceChecksum) {
            return $this->build();
        }

        return $table;
    }

    /**
     * @param callable(): StaleBuildError $error
     */
    private function rebuildOr(callable $error): RouteTable
    {
        if (!$this->mayRebuild) {
            throw $error();
        }

        return $this->build();
    }

    private function write(string $name, string $contents): void
    {
        $file = $this->cache->file($name);

        if (file_put_contents($file, $contents) === false) {
            throw ContractBuildError::because(sprintf('Build artefact %s could not be written.', $file));
        }
    }
}

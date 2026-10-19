<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use Mailadmin\Http\Routing\ContractBuild;
use Mailadmin\Http\Routing\RouteCompiler;
use Mailadmin\Http\Routing\RouteTable;
use Mailadmin\Http\Routing\SpecificationBundler;
use Mailadmin\Runtime\CacheDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Base for the tests that run against the **real** contract in `openapi/`.
 *
 * The suite builds the artefacts itself rather than assuming `composer build`
 * has run, so `vendor/bin/phpunit` works on a fresh checkout. It writes into the
 * project's own `var/cache/`, which is exactly what the build command does — the
 * result is a correctly built tree either way.
 */
abstract class ContractTestCase extends TestCase
{
    private static ?RouteTable $table = null;

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    protected static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    protected static function build(): ContractBuild
    {
        return new ContractBuild(
            bundler: new SpecificationBundler(self::projectRoot() . '/openapi'),
            compiler: new RouteCompiler(self::projectRoot() . '/src/Http/Handler'),
            cache: new CacheDirectory(self::projectRoot() . '/var/cache'),
            mayRebuild: true,
        );
    }

    protected static function routeTable(): RouteTable
    {
        return self::$table ??= self::build()->build();
    }

    /**
     * The bundled contract as it lands on disk — parsed back from the YAML, so
     * that anything the serialisation gets wrong shows up here rather than in a
     * consumer.
     *
     * @return array<string, mixed>
     */
    protected static function contract(): array
    {
        if (self::$document !== null) {
            return self::$document;
        }

        self::routeTable();

        $file = self::projectRoot() . '/var/cache/' . ContractBuild::BUNDLED_YAML;

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parse((string) file_get_contents($file));

        return self::$document = $parsed;
    }

    /**
     * Every operation in the contract, keyed by `"<METHOD> <path>"`.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function operations(): array
    {
        $operations = [];

        /** @var mixed $pathItem */
        foreach (self::contract()['paths'] as $path => $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }

            /** @var mixed $operation */
            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !in_array($method, RouteCompiler::HTTP_METHODS, true)) {
                    continue;
                }

                if (is_array($operation)) {
                    /** @var array<string, mixed> $operation */
                    $operations[strtoupper($method) . ' ' . $path] = $operation;
                }
            }
        }

        return $operations;
    }
}

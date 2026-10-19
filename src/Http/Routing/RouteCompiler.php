<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

use Mailadmin\Domain\Access\Permission;
use Mailadmin\Http\Handler\ApiHandler;

/**
 * Turns the bundled contract into the route table (U-4).
 *
 * The mapping from an operation to the class that answers it is a **convention**,
 * checked at build time, not a registry:
 *
 *     operationId + first tag  ->  Mailadmin\Http\Handler\{UpperCamel(tag)}\{UpperCamel(operationId)}Handler
 *
 *     getHealth   + [health]   ->  Mailadmin\Http\Handler\Health\GetHealthHandler
 *     listDomains + [domains]  ->  Mailadmin\Http\Handler\Domains\ListDomainsHandler
 *
 * A registry would be one more file every package has to edit, and one more
 * place to forget something. The convention has neither problem, and the build
 * closes the loop in both directions: an operation without a handler fails, and
 * a handler without an operation fails too — so nothing can be reachable that
 * the contract does not describe, and nothing described can be missing.
 *
 * `x-permission` is required on every operation, and since S3 it must also name
 * a case of {@see Permission}. Enforcing it against a role at request time stays
 * S3's job; what happens here is that a contract naming a permission nobody
 * implements cannot be compiled at all.
 */
final readonly class RouteCompiler
{
    /**
     * The HTTP methods OpenAPI allows as keys of a path item. Anything else at
     * that level is a path-wide field, not an operation.
     *
     * @var list<string>
     */
    public const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * Path-item keys that are not operations. `$ref` is absent on purpose: the
     * bundler has already resolved references, and a path item that is nothing
     * but a pointer hides which file owns the path.
     *
     * @var list<string>
     */
    private const array PATH_ITEM_FIELDS = ['summary', 'description', 'servers', 'parameters'];

    public function __construct(
        private string $handlerDirectory,
        private string $handlerNamespace = 'Mailadmin\\Http\\Handler',
    ) {
    }

    /**
     * @param array<string, mixed> $document the bundled contract
     *
     * @throws ContractBuildError
     */
    public function compile(array $document, string $specChecksum, string $sourceChecksum): RouteTable
    {
        $this->assertVersion($document);

        $basePath = $this->basePath($document);
        $routes = [];
        /** @var array<string, string> $seenOperationIds */
        $seenOperationIds = [];
        /** @var array<class-string, string> $expectedHandlers */
        $expectedHandlers = [];

        /** @var mixed $paths */
        $paths = $document['paths'] ?? null;

        if (!is_array($paths) || $paths === []) {
            throw ContractBuildError::because('The bundled contract declares no paths.');
        }

        ksort($paths);

        /** @var mixed $pathItem */
        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                throw ContractBuildError::because('A path item in the bundled contract is not a mapping.');
            }

            $this->assertPathParametersAreRoutable($path);

            /** @var mixed $operation */
            foreach ($pathItem as $key => $operation) {
                if (!is_string($key)) {
                    continue;
                }

                $method = strtolower($key);

                if (in_array($method, self::PATH_ITEM_FIELDS, true) || str_starts_with($method, 'x-')) {
                    continue;
                }

                if (!in_array($method, self::HTTP_METHODS, true)) {
                    throw ContractBuildError::because(sprintf(
                        'Path "%s" declares "%s", which is neither an HTTP method nor a path-item field.',
                        $path,
                        $key,
                    ));
                }

                if (!is_array($operation)) {
                    throw ContractBuildError::because(sprintf(
                        'Operation %s %s is not a mapping.',
                        strtoupper($method),
                        $path,
                    ));
                }

                /** @var array<string, mixed> $operation */
                $route = $this->compileOperation($path, $method, $operation, $basePath);

                if (isset($seenOperationIds[$route->operationId])) {
                    throw ContractBuildError::because(sprintf(
                        'operationId "%s" is used twice: for %s and for %s %s. It has to be unique — '
                        . 'it names the handler class and, in W1, the generated client method.',
                        $route->operationId,
                        $seenOperationIds[$route->operationId],
                        strtoupper($method),
                        $path,
                    ));
                }

                $seenOperationIds[$route->operationId] = strtoupper($method) . ' ' . $path;
                $expectedHandlers[$route->handler] = $route->operationId;
                $routes[] = $route;
            }
        }

        $this->assertNoOrphanedHandlers($expectedHandlers);

        return new RouteTable(
            specChecksum: $specChecksum,
            sourceChecksum: $sourceChecksum,
            basePath: $basePath,
            routes: $routes,
            generatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function compileOperation(string $path, string $method, array $operation, string $basePath): Route
    {
        $where = sprintf('%s %s', strtoupper($method), $path);

        /** @var mixed $operationId */
        $operationId = $operation['operationId'] ?? null;

        if (!is_string($operationId) || preg_match('/^[a-z][A-Za-z0-9]*$/', $operationId) !== 1) {
            throw ContractBuildError::because(sprintf(
                'Operation %s has no usable operationId. It is required and must be lowerCamelCase '
                . '(letters and digits only), because the handler class name is derived from it.',
                $where,
            ));
        }

        /** @var mixed $tags */
        $tags = $operation['tags'] ?? null;

        if (!is_array($tags) || !is_string($tags[0] ?? null) || $tags[0] === '') {
            throw ContractBuildError::because(sprintf(
                'Operation %s ("%s") has no tags. The first tag decides the handler namespace (U-4), '
                . 'so at least one is required.',
                $where,
                $operationId,
            ));
        }

        /** @var string $tag */
        $tag = $tags[0];

        if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $tag) !== 1) {
            throw ContractBuildError::because(sprintf(
                'Operation %s ("%s") carries the tag "%s". A tag is lower-case, digits and hyphens — '
                . 'it becomes a namespace segment.',
                $where,
                $operationId,
                $tag,
            ));
        }

        /** @var mixed $permission */
        $permission = $operation['x-permission'] ?? null;

        if (!is_string($permission) || trim($permission) === '') {
            throw ContractBuildError::because(sprintf(
                'Operation %s ("%s") declares no x-permission. Every operation states who may call it, '
                . 'even a public one ("x-permission: public"); S3 enforces it, F2 only insists that it is '
                . 'there — an operation that forgets it would otherwise default to reachable.',
                $where,
                $operationId,
            ));
        }

        if (Permission::tryFrom(trim($permission)) === null) {
            // S3. A permission the code does not know is never granted, so
            // without this the contract could name `domain.wrtie` and produce an
            // operation that answers 500 for everybody — findable only by trying
            // it. The build is the right place to find it instead.
            throw ContractBuildError::because(sprintf(
                'Operation %s ("%s") declares the permission "%s", which is not in %s. '
                . 'Add the case there if the permission is new, or correct the contract.',
                $where,
                $operationId,
                trim($permission),
                Permission::class,
            ));
        }

        $handler = $this->handlerClass($tag, $operationId);

        if (!class_exists($handler)) {
            throw ContractBuildError::because(sprintf(
                'Operation %s ("%s") has no handler. Expected the class %s in %s.',
                $where,
                $operationId,
                $handler,
                $this->handlerFile($tag, $operationId),
            ));
        }

        if (!is_subclass_of($handler, ApiHandler::class)) {
            throw ContractBuildError::because(sprintf(
                'The handler %s for operation "%s" does not implement %s.',
                $handler,
                $operationId,
                ApiHandler::class,
            ));
        }

        return new Route(
            method: strtoupper($method),
            path: $basePath . $path,
            specPath: $path,
            operationId: $operationId,
            handler: $handler,
            permission: trim($permission),
            tag: $tag,
        );
    }

    /**
     * The other direction: a handler class nobody routes to.
     *
     * Only the first level below `src/Http/Handler/` is scanned, which is
     * exactly where the convention puts handlers — one directory per tag. The
     * top level holds {@see ApiHandler} and nothing else.
     *
     * @param array<class-string, string> $expected
     */
    private function assertNoOrphanedHandlers(array $expected): void
    {
        $orphans = [];

        foreach ($this->handlerFiles() as $file => $class) {
            if (!isset($expected[$class])) {
                $orphans[] = sprintf('%s (%s)', $class, $file);
            }
        }

        if ($orphans !== []) {
            sort($orphans);

            throw ContractBuildError::withDetails(
                'These handler classes answer no operation in the contract. Either add the operation or '
                . 'delete the class — a handler that is not in the contract is unreachable code at best '
                . 'and an undocumented endpoint at worst:',
                $orphans,
            );
        }
    }

    /**
     * @return array<string, class-string> file path => class name
     */
    private function handlerFiles(): array
    {
        if (!is_dir($this->handlerDirectory)) {
            return [];
        }

        $found = glob($this->handlerDirectory . '/*/*Handler.php');

        if ($found === false) {
            return [];
        }

        sort($found);
        $classes = [];

        foreach ($found as $file) {
            $relative = substr($file, strlen($this->handlerDirectory) + 1, -strlen('.php'));
            /** @var class-string $class */
            $class = $this->handlerNamespace . '\\' . str_replace('/', '\\', $relative);
            $classes[$file] = $class;
        }

        return $classes;
    }

    /**
     * @return class-string
     */
    private function handlerClass(string $tag, string $operationId): string
    {
        /** @var class-string $class */
        $class = sprintf(
            '%s\\%s\\%sHandler',
            $this->handlerNamespace,
            self::upperCamel($tag),
            ucfirst($operationId),
        );

        return $class;
    }

    private function handlerFile(string $tag, string $operationId): string
    {
        return sprintf('%s/%s/%sHandler.php', $this->handlerDirectory, self::upperCamel($tag), ucfirst($operationId));
    }

    public static function upperCamel(string $tag): string
    {
        return implode('', array_map(
            static fn (string $part): string => ucfirst($part),
            preg_split('/[-_]/', $tag) ?: [$tag],
        ));
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertVersion(array $document): void
    {
        /** @var mixed $version */
        $version = $document['openapi'] ?? null;

        if (!is_string($version) || !str_starts_with($version, '3.0.')) {
            throw ContractBuildError::because(sprintf(
                'The contract declares OpenAPI version "%s". U-2 fixes this project on 3.0.x, because '
                . 'league/openapi-psr7-validator supports 3.1 only in part.',
                is_scalar($version) ? (string) $version : gettype($version),
            ));
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function basePath(array $document): string
    {
        /** @var mixed $servers */
        $servers = $document['servers'] ?? null;

        if (!is_array($servers) || !is_array($servers[0] ?? null) || !is_string($servers[0]['url'] ?? null)) {
            throw ContractBuildError::because(
                'The contract declares no servers[0].url. U-10 puts the API version in the path, and the '
                . 'route table takes the prefix from there rather than repeating it in every path.',
            );
        }

        /** @var string $url */
        $url = $servers[0]['url'];

        if (!str_starts_with($url, '/')) {
            throw ContractBuildError::because(sprintf(
                'servers[0].url is "%s". It has to be an absolute path such as "/api/v1"; an absolute URL '
                . 'would tie the contract to one installation.',
                $url,
            ));
        }

        return rtrim($url, '/');
    }

    /**
     * FastRoute and OpenAPI write path parameters the same way, `{name}`, but
     * FastRoute also reads `{name:regex}`. Restricting the name keeps the two
     * syntaxes from meeting.
     */
    private function assertPathParametersAreRoutable(string $path): void
    {
        if (preg_match_all('/\{([^}]*)\}/', $path, $matches) === false) {
            return;
        }

        foreach ($matches[1] as $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw ContractBuildError::because(sprintf(
                    'Path "%s" has the parameter "{%s}". A parameter name must be a plain identifier; '
                    . 'a colon would be read as a FastRoute pattern.',
                    $path,
                    $name,
                ));
            }
        }
    }
}

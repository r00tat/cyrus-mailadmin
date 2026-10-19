<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

/**
 * One compiled operation: what the contract says, plus the class that answers it.
 *
 * `path` carries the version prefix from `servers` (U-10) and is what FastRoute
 * matches; `specPath` is the template as written in the contract and is what the
 * schema validator addresses. Keeping both means the version can move without
 * every consumer having to strip it again.
 */
final readonly class Route
{
    /**
     * @param class-string $handler
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $specPath,
        public string $operationId,
        public string $handler,
        public string $permission,
        public string $tag,
    ) {
    }

    /**
     * The mark the validation middleware leaves and the dispatcher checks —
     * method plus contract path, which identifies an operation without the
     * dispatcher having to trust an identifier the middleware made up.
     */
    public function validationMark(): string
    {
        return $this->method . ' ' . $this->specPath;
    }

    /**
     * @return array{method: string, path: string, spec_path: string, operation_id: string, handler: string, permission: string, tag: string}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'spec_path' => $this->specPath,
            'operation_id' => $this->operationId,
            'handler' => $this->handler,
            'permission' => $this->permission,
            'tag' => $this->tag,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['method', 'path', 'spec_path', 'operation_id', 'handler', 'permission', 'tag'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                throw StaleBuildError::unreadable(sprintf('the compiled route table (missing "%s")', $key));
            }
        }

        /** @var array{method: string, path: string, spec_path: string, operation_id: string, handler: string, permission: string, tag: string} $data */

        /** @var class-string $handler */
        $handler = $data['handler'];

        return new self(
            method: $data['method'],
            path: $data['path'],
            specPath: $data['spec_path'],
            operationId: $data['operation_id'],
            handler: $handler,
            permission: $data['permission'],
            tag: $data['tag'],
        );
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the decoded JSON body of a request that has already been validated.
 *
 * By the time a handler runs, {@see Middleware\JsonBodyMiddleware} has decoded
 * the body and {@see Middleware\OpenApiValidationMiddleware} has checked it
 * against the contract — so a field the schema declares required is there, and
 * it has the declared type. This helper exists anyway, for two reasons.
 *
 * PSR-7's `getParsedBody()` is typed `object|array|null`, so every handler would
 * otherwise open with the same four lines of narrowing to satisfy the analyser.
 * And a handler that would fatal on an unexpected shape is one refactor away
 * from being reachable: the dispatcher's insistence on a validation mark is a
 * good guard, not a proof.
 *
 * A missing or wrongly typed field therefore yields the empty string or the
 * default — never an exception. The place to report a malformed request is the
 * validator, with the field name and the constraint; a second, later error from
 * here would be worse and would arrive as a `500`.
 */
final class ParsedBody
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(ServerRequestInterface $request): array
    {
        /** @var mixed $body */
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return [];
        }

        $fields = [];

        /** @var mixed $value */
        foreach ($body as $name => $value) {
            if (is_string($name)) {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function text(array $body, string $field, string $default = ''): string
    {
        /** @var mixed $value */
        $value = $body[$field] ?? null;

        return is_string($value) ? $value : $default;
    }
}

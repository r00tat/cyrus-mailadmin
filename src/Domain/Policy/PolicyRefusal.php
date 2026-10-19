<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use RuntimeException;

/**
 * A policy said no — `403` ({@see AccessDenied}) or the `404` that hides a
 * foreign object ({@see ResourceNotVisible}).
 *
 * **Deliberately not a {@see \Mailadmin\Domain\Exception\DomainError}** (U-98).
 * The two used to extend it, and every handler turns a `DomainError` into a
 * `422` that names the offending field — the right answer for a value the model
 * refused, and exactly the wrong one for a refusal whose whole point is to say
 * nothing about the object. A handler that forgot to rethrow the policy
 * exceptions ahead of its `catch (DomainError)` answered `422` where the
 * contract promises `404`. Taking them out of the hierarchy removes the trap for
 * every handler at once, including the ones not written yet;
 * `tests/Unit/Http/HandlerCatchGuardTest.php` keeps it removed.
 *
 * They travel from the use case to `AuthorizationMiddleware`, which is the one
 * place that renders them. Nothing in between catches them.
 *
 * The shape is the one `DomainError` has — a stable `code()` and a `context()`
 * for the log — so that the middleware and the tests read them the same way.
 */
abstract class PolicyRefusal extends RuntimeException
{
    /**
     * @param array<string, string|int> $context
     */
    protected function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** The stable error code (U-7): `request.forbidden` or `request.not_found`. */
    final public function code(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string|int>
     */
    final public function context(): array
    {
        return $this->context;
    }

    /**
     * A value made safe to put into a message: printable ASCII only, shortened.
     * The same rule {@see \Mailadmin\Domain\Exception\DomainError} applies.
     */
    final protected static function excerpt(string $value, int $limit = 80): string
    {
        $printable = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';

        if (strlen($printable) > $limit) {
            return substr($printable, 0, $limit) . '...';
        }

        return $printable;
    }
}

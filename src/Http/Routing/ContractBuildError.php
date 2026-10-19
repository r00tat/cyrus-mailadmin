<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

use RuntimeException;

/**
 * A fault in the contract itself, found while bundling or compiling it.
 *
 * Every one of these stops the build. That is the whole point of rule three in
 * `CLAUDE.md`: specification and implementation cannot drift, because a
 * mismatch is a build failure rather than a `404` somebody discovers later.
 *
 * The messages are addressed to whoever is editing the contract, so they name
 * the file, the operation and the expected class — never just "invalid schema".
 */
final class ContractBuildError extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    /**
     * @param list<string> $lines
     */
    public static function withDetails(string $message, array $lines): self
    {
        return new self($message . "\n  - " . implode("\n  - ", $lines));
    }
}

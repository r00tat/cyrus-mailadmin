<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

use RuntimeException;

/**
 * The build artefacts under `var/cache/` are missing or do not belong to the
 * contract that sits next to them (U-8).
 *
 * Outside development this refuses the request with `503` and the code
 * `migration.build_stale` rather than recompiling: compiling under load has the
 * same concurrency problem as migrating under load (architecture 10.2), and a
 * deployment that forgot `composer build` should be visible, not papered over.
 *
 * In development {@see ContractBuild} rebuilds instead of throwing this.
 */
final class StaleBuildError extends RuntimeException
{
    public static function missing(string $artefact): self
    {
        return new self(sprintf(
            'Build artefact %s is missing. Run "composer build"; a deployment must ship var/cache.',
            $artefact,
        ));
    }

    public static function checksumMismatch(string $artefact): self
    {
        return new self(sprintf(
            'Build artefact %s does not match the checksum recorded when the routes were compiled. '
            . 'The contract and the compiled route table are out of step; run "composer build".',
            $artefact,
        ));
    }

    public static function unreadable(string $artefact): self
    {
        return new self(sprintf('Build artefact %s exists but could not be read.', $artefact));
    }
}

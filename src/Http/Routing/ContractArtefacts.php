<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

use cebe\openapi\spec\OpenApi;

/**
 * Access to the build artefacts derived from the contract.
 *
 * An interface rather than the concrete {@see ContractBuild} because two very
 * different things need it: the running application, which reads what
 * `composer build` produced, and the tests, which point the same pipeline at a
 * fixture contract in a temporary directory. Both get the real bundler,
 * compiler and validator that way — a test double of the *artefacts* would
 * prove nothing about the pipeline that makes them.
 */
interface ContractArtefacts
{
    /**
     * @throws StaleBuildError when the artefacts are missing or do not match the contract (U-8)
     */
    public function routeTable(): RouteTable;

    /**
     * The parsed contract, for request and response validation.
     *
     * @throws StaleBuildError
     */
    public function specification(): OpenApi;

    /**
     * The bundled contract as JSON — what `/api/docs/openapi.json` serves.
     *
     * @throws StaleBuildError
     */
    public function bundledJson(): string;
}

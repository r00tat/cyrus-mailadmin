<?php

declare(strict_types=1);

/**
 * Container definitions for F2 — the API contract and the dispatcher.
 *
 * One file per package (U-5). Only what autowiring cannot work out on its own
 * is here: where the contract lives, where handlers live, and the three
 * switches that depend on the environment (U-8, U-9).
 *
 * The middleware chain itself stays in `config/di/core.php`; this file only
 * supplies the parts it plugs in.
 */

use Mailadmin\Config\Config;
use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Middleware\OpenApiValidationMiddleware;
use Mailadmin\Http\NotFoundHandler;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Http\RouteMiddleware;
use Mailadmin\Http\Routing\ContractArtefacts;
use Mailadmin\Http\Routing\ContractBuild;
use Mailadmin\Http\Routing\DocumentationEndpoint;
use Mailadmin\Http\Routing\RouteCompiler;
use Mailadmin\Http\Routing\SpecificationBundler;
use Mailadmin\Runtime\CacheDirectory;
use Mailadmin\Runtime\Paths;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

return [
    // ------------------------------------------------------------------
    // The contract pipeline. The same three classes serve the build command
    // and the running application, which is why a stale artefact is noticed
    // by the code that would otherwise use it.
    // ------------------------------------------------------------------
    SpecificationBundler::class => static fn (Paths $paths): SpecificationBundler
        => new SpecificationBundler($paths->join('openapi')),

    RouteCompiler::class => static fn (Paths $paths): RouteCompiler
        => new RouteCompiler($paths->join('src', 'Http', 'Handler')),

    ContractArtefacts::class => static fn (
        SpecificationBundler $bundler,
        RouteCompiler $compiler,
        CacheDirectory $cache,
        Config $config,
    ): ContractArtefacts => new ContractBuild(
        bundler: $bundler,
        compiler: $compiler,
        cache: $cache,
        // U-8: development rebuilds when the contract has moved on; in
        // production a mismatch is a deployment fault and the answer is 503,
        // because compiling under load has the same concurrency problem as
        // migrating under load (architecture 10.2).
        //
        // `test` is grouped with `dev` rather than with `prod` — U-8 names only
        // `dev`, but a test run has to be able to work from a fresh checkout,
        // and the test environment is never a served installation. The refusal
        // path itself stays covered: ContractDispatchTest builds a ContractBuild
        // with `mayRebuild: false` on purpose.
        mayRebuild: !$config->environment()->isProduction(),
    ),

    // ------------------------------------------------------------------
    // U-9: request validation is always on — it is the input boundary.
    // Response validation is early warning for us, not a runtime feature, so
    // it stays out of production.
    // ------------------------------------------------------------------
    OpenApiValidationMiddleware::class => static fn (
        ContractArtefacts $artefacts,
        ProblemResponseFactory $problems,
        LoggerInterface $logger,
        Config $config,
    ): OpenApiValidationMiddleware => new OpenApiValidationMiddleware(
        artefacts: $artefacts,
        problems: $problems,
        logger: $logger,
        basePath: $config->basePath(),
        validateResponses: !$config->environment()->isProduction(),
    ),

    // ------------------------------------------------------------------
    // U-9: the contract is browsable outside production. In production the
    // path does not exist unless MAILADMIN_API_DOCS says so — the schema
    // describes the whole attack surface, and an administration tool gains
    // nothing operationally from publishing it.
    // ------------------------------------------------------------------
    DocumentationEndpoint::class => static fn (
        ContractArtefacts $artefacts,
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        Config $config,
    ): DocumentationEndpoint => new DocumentationEndpoint(
        artefacts: $artefacts,
        responses: $responses,
        streams: $streams,
        enabled: $config->boolOrDefault(
            DocumentationEndpoint::ENABLE,
            !$config->environment()->isProduction(),
        ),
        basePath: $config->basePath(),
    ),

    // The route-middleware chain — authentication (S2), then authorisation (S3)
    // — is listed in RouteMiddleware rather than assembled here, so that its
    // order lives in one readable place instead of depending on the order the
    // container merges `config/di/*.php` in. See src/Http/RouteMiddleware.php.
    Dispatcher::class => static fn (
        ContractArtefacts $artefacts,
        ContainerInterface $container,
        ProblemResponseFactory $problems,
        NotFoundHandler $notFound,
        LoggerInterface $logger,
        DocumentationEndpoint $documentation,
        Config $config,
        RouteMiddleware $routeMiddleware,
    ): Dispatcher => new Dispatcher(
        artefacts: $artefacts,
        // Handlers are resolved on demand: one request touches one of them, and
        // building the rest would mean opening every dependency they have.
        handlers: $container,
        problems: $problems,
        notFound: $notFound,
        logger: $logger,
        documentation: $documentation,
        basePath: $config->basePath(),
        // Request validation is never off (U-9), so a routed request that was
        // not validated means the chain is wired wrongly. Fail loudly.
        requireValidatedRequests: true,
        routeMiddleware: $routeMiddleware->all(),
    ),
];

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Serves the contract under `/api/docs` (U-9).
 *
 * **There is no Redoc here, and that is a decision, not an omission.** The
 * original reason was the Content-Security-Policy: Redoc renders through
 * styled-components, which writes `<style>` elements into the page at runtime,
 * and the policy did not allow that. **That obstacle is gone** — U-35 admits
 * `style-src 'unsafe-inline'` for Material UI. What is left is the second
 * reason: vendoring a megabyte of bundled JavaScript into the repository for a
 * viewer that can just as well be pointed at the JSON below. Whether that still
 * holds is open point O-12; until it is decided, this endpoint stays as it is.
 *
 * So the endpoint serves the machine-readable contract instead:
 *
 *   GET /api/docs               302 to the document below
 *   GET /api/docs/openapi.json  the bundled contract as JSON
 *
 * Any renderer the operator likes — Redoc, Swagger UI, an editor — can be
 * pointed at that URL and runs in its own origin, where its own CSP applies.
 * Should W1 later ship a self-hosted viewer with the frontend bundle, it takes
 * over `/api/docs` and this class keeps the JSON.
 *
 * Availability follows U-9: on in development and test, off in production
 * unless `MAILADMIN_API_DOCS` says otherwise. Off means the path does not
 * exist — {@see \Mailadmin\Http\Dispatcher} then answers `404` like any other
 * unknown path, so nothing announces that documentation could be switched on.
 *
 * The paths deliberately sit outside `/api/v1` and outside the contract: they
 * are not API operations, they have no `operationId`, and a client generated
 * from the contract must not find them.
 */
final readonly class DocumentationEndpoint
{
    public const string INDEX_PATH = '/api/docs';
    public const string SPECIFICATION_PATH = '/api/docs/openapi.json';

    /** Environment variable that switches the endpoint on in production (U-9). */
    public const string ENABLE = 'MAILADMIN_API_DOCS';

    public function __construct(
        private ContractArtefacts $artefacts,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private bool $enabled,
        private string $basePath,
    ) {
    }

    /**
     * @param string $path the request path with the mount prefix already removed
     */
    public function matches(string $path): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $path === self::INDEX_PATH
            || $path === self::INDEX_PATH . '/'
            || $path === self::SPECIFICATION_PATH;
    }

    /**
     * @throws StaleBuildError when the contract has not been built
     */
    public function respond(string $path): ResponseInterface
    {
        if ($path === self::SPECIFICATION_PATH) {
            return $this->responses
                ->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($this->artefacts->bundledJson()));
        }

        return $this->responses
            ->createResponse(302)
            ->withHeader('Location', $this->basePath . self::SPECIFICATION_PATH);
    }
}

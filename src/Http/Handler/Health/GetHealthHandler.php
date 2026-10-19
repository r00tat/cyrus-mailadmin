<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Health;

use Mailadmin\Application\Maintenance\HealthMonitor;
use Mailadmin\Http\Handler\ApiHandler;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * `GET /health` — reachable without a token, for monitoring.
 *
 * Since A6 it probes the database, IMAP and ManageSieve (U-121) and answers
 * `503` when one of them fails. The representation stays thin — component and
 * status, no version, no host names, no error text — and may be up to ten
 * seconds old, so that an unauthenticated caller cannot turn this endpoint
 * into a stream of administrator logins at Cyrus. Concurrent callers share
 * one round of probes (U-123). The detail is at
 * `/maintenance/health`, for a superuser.
 *
 * No {@see \Mailadmin\Domain\Access\ActorContext}: there is no actor on a public
 * operation, and the monitor it calls is not a use case that acts for anybody.
 */
final readonly class GetHealthHandler implements ApiHandler
{
    public function __construct(
        private HealthMonitor $monitor,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $report = $this->monitor->publicReport();

        $body = json_encode(
            $report->toPublic(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $this->responses
            ->createResponse($report->isHealthy() ? 200 : 503)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($body));
    }
}

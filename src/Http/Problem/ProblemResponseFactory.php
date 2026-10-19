<?php

declare(strict_types=1);

namespace Mailadmin\Http\Problem;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Turns a {@see ProblemDetails} into a PSR-7 response.
 *
 * Every error response in the application goes through here, which is what makes
 * "no exception message reaches the client" a property of one file rather than a
 * habit.
 */
final readonly class ProblemResponseFactory
{
    public const string CONTENT_TYPE = 'application/problem+json';

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function create(ProblemDetails $problem): ResponseInterface
    {
        return $this->responses
            ->createResponse($problem->status)
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withBody($this->streams->createStream($problem->toJson()));
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Middleware;

use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Decodes a JSON request body into the parsed body.
 *
 * The API speaks JSON and nothing else (architecture 7.1). Deliberately absent:
 * `application/x-www-form-urlencoded` and `multipart/form-data`. Nothing in
 * mailadmin uploads a file, and not accepting those content types removes a
 * class of parser surprises rather than handling it.
 *
 * A malformed body is answered here rather than thrown, because the answer is
 * the same in every environment and carries no internal detail. Schema
 * validation of the decoded structure is a separate concern and belongs to F2.
 *
 * Note that this runs *before* routing (F2). That is intentional — a body that
 * is not decodable is rejected without the router ever looking for a handler.
 */
final readonly class JsonBodyMiddleware implements MiddlewareInterface
{
    /**
     * Methods for which a body is meaningful. A body on `GET` or `DELETE` is
     * ignored rather than rejected: RFC 9110 permits it, and no endpoint reads it.
     *
     * @var list<string>
     */
    private const array METHODS_WITH_BODY = ['POST', 'PUT', 'PATCH'];

    /** Maximum decoding depth. Deeper input is malformed as far as this API is concerned. */
    private const int MAX_DEPTH = 32;

    public function __construct(private ProblemResponseFactory $problems)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::METHODS_WITH_BODY, true)) {
            return $handler->handle($request);
        }

        $body = (string) $request->getBody();

        if (trim($body) === '') {
            // An empty body is legitimate — `PUT /…/vacation` with no payload,
            // for instance. Whether it is allowed is the schema's business (F2).
            return $handler->handle($request);
        }

        if (!$this->isJson($request->getHeaderLine('Content-Type'))) {
            return $this->problems->create(new ProblemDetails(
                status: 415,
                code: ErrorCode::UNSUPPORTED_MEDIA_TYPE,
                title: 'Unsupported Media Type',
                detail: 'The request body must be application/json.',
                instance: $request->getUri()->getPath(),
                requestId: $this->requestId($request),
            ));
        }

        try {
            $decoded = json_decode($body, associative: true, depth: self::MAX_DEPTH, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->malformed($request);
        }

        if (!is_array($decoded)) {
            // A bare scalar or `null` at the top level is never a valid payload.
            return $this->malformed($request);
        }

        return $handler->handle($request->withParsedBody($decoded));
    }

    private function malformed(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problems->create(new ProblemDetails(
            status: 400,
            code: ErrorCode::MALFORMED_JSON,
            title: 'Bad Request',
            detail: 'The request body is not a valid JSON object or array.',
            instance: $request->getUri()->getPath(),
            requestId: $this->requestId($request),
        ));
    }

    /**
     * Accepts `application/json` and the `+json` structured suffix, so that
     * `application/merge-patch+json` works without a second code path.
     */
    private function isJson(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    private function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}

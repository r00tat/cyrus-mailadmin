<?php

declare(strict_types=1);

namespace Mailadmin\Http\Middleware;

use League\OpenAPIValidation\PSR7\Exception\NoPath;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidCookies;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidHeaders;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidParameter;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidPath;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidQueryArgs;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidSecurity;
use League\OpenAPIValidation\PSR7\Exception\Validation\RequiredParameterMissing;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use League\OpenAPIValidation\Schema\Exception\ContentTypeMismatch;
use League\OpenAPIValidation\Schema\Exception\KeywordMismatch;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Http\Routing\BasePath;
use Mailadmin\Http\Routing\ContractArtefacts;
use Mailadmin\Http\Routing\StaleBuildError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * The input boundary: every request is checked against the contract before a
 * handler sees it (architecture 4).
 *
 * Request validation runs in **every** environment, production included. A
 * rejection here costs one schema walk; the same mistake caught three layers
 * down costs a database round trip and a much less specific error. Response
 * validation is a development tool and runs only where U-9 says it does.
 *
 * Two things are deliberate and worth not undoing:
 *
 * **A request whose path the contract does not know is passed through, not
 * rejected.** The dispatcher owns "no such route" and answers `404` — or `405`
 * with `Allow`, which this middleware could not produce. One place decides what
 * a path means.
 *
 * **No input value is ever echoed back or logged.** The `errors` map names the
 * field and the constraint it failed (`body.quota` → `maximum`), never the
 * value. A request body may carry a password; the validator's own exception
 * messages interpolate the offending value, which is exactly why those messages
 * are not used for either the response or the log.
 */
final class OpenApiValidationMiddleware implements MiddlewareInterface
{
    /**
     * Mark left on a validated request: `"<METHOD> <contract path>"`.
     *
     * {@see \Mailadmin\Http\Dispatcher} refuses to run a handler without it, so
     * a chain that is wired wrongly fails loudly instead of quietly serving
     * unvalidated input.
     */
    public const string ATTRIBUTE = 'mailadmin.validated_operation';

    private ?ServerRequestValidator $requests = null;

    private ?ResponseValidator $responses = null;

    public function __construct(
        private readonly ContractArtefacts $artefacts,
        private readonly ProblemResponseFactory $problems,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
        private readonly bool $validateResponses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = BasePath::strip($request->getUri()->getPath(), $this->basePath);
        $candidate = $request->withUri($request->getUri()->withPath($path));

        try {
            $validator = $this->requestValidator();
        } catch (StaleBuildError) {
            // Nothing to validate against. The dispatcher below answers 503 with
            // migration.build_stale; producing a second, differently worded
            // failure here would only obscure that.
            return $handler->handle($request);
        }

        try {
            $address = $validator->validate($candidate);
        } catch (NoPath) {
            // Includes NoOperation. Unknown path or wrong method — the
            // dispatcher's business.
            return $handler->handle($request);
        } catch (InvalidSecurity $failure) {
            return $this->unauthenticated($request, $failure);
        } catch (ValidationFailed $failure) {
            return $this->unprocessable($request, $failure);
        }

        $response = $handler->handle(
            $request->withAttribute(self::ATTRIBUTE, strtoupper($address->method()) . ' ' . $address->path()),
        );

        if ($this->validateResponses) {
            $this->checkResponse($address, $response);
        }

        return $response;
    }

    private function unprocessable(ServerRequestInterface $request, ValidationFailed $failure): ResponseInterface
    {
        $errors = self::errorsFrom($failure);

        // Field names and constraint names only — see the class docblock.
        $this->logger->info('Request rejected by schema validation.', [
            'http_method' => $request->getMethod(),
            'http_path' => $request->getUri()->getPath(),
            'validation_errors' => $errors,
        ]);

        return $this->problems->create(new ProblemDetails(
            status: 422,
            code: ErrorCode::VALIDATION_FAILED,
            title: 'Unprocessable Content',
            detail: 'The request does not match the API contract.',
            instance: $request->getUri()->getPath(),
            requestId: $this->requestId($request),
            errors: $errors,
        ));
    }

    /**
     * The contract declares a bearer token on every operation that is not
     * explicitly public, so a missing or malformed `Authorization` header fails
     * validation. That is an authentication problem, and `401` says so — a `422`
     * would send the client looking at its request body. Whether the token is
     * *valid* is S2's question, and what it permits is S3's.
     */
    private function unauthenticated(ServerRequestInterface $request, InvalidSecurity $failure): ResponseInterface
    {
        unset($failure);

        return $this->problems
            ->create(new ProblemDetails(
                status: 401,
                code: ErrorCode::UNAUTHENTICATED,
                title: 'Unauthorized',
                detail: 'This operation requires a bearer token.',
                instance: $request->getUri()->getPath(),
                requestId: $this->requestId($request),
            ))
            ->withHeader('WWW-Authenticate', 'Bearer');
    }

    /**
     * A response that does not match the contract is our bug, not the client's.
     * It is logged — loudly — and the response goes out unchanged: turning a
     * documentation gap into a `500` would make a cosmetic mismatch look like an
     * outage, and the contract tests are where this fails a build.
     */
    private function checkResponse(OperationAddress $address, ResponseInterface $response): void
    {
        try {
            $this->responseValidator()->validate($address, $response);
        } catch (StaleBuildError) {
            // Already reported by the dispatcher.
        } catch (\Throwable $failure) {
            $this->logger->error('Response does not match the API contract.', [
                'operation' => $address->method() . ' ' . $address->path(),
                'http_status' => $response->getStatusCode(),
                'violation' => $failure::class,
                'violation_fields' => $failure instanceof ValidationFailed ? self::errorsFrom($failure) : [],
            ]);
        }
    }

    /**
     * Maps a validation failure onto `field => constraint`.
     *
     * The keys are dot paths rooted in the part of the request that failed —
     * `body.name`, `query.per_page`, `header`. The values are OpenAPI keyword
     * names (`required`, `type`, `enum`, `maximum`), so the frontend can
     * translate them without parsing English.
     *
     * @return array<string, string>
     */
    public static function errorsFrom(ValidationFailed $failure): array
    {
        $previous = $failure->getPrevious();

        return match (true) {
            $failure instanceof InvalidBody => [self::path('body', $previous) => self::constraint($previous)],
            $failure instanceof InvalidQueryArgs => [self::parameter('query', $previous) => self::constraint($previous)],
            $failure instanceof InvalidHeaders => [self::parameter('header', $previous) => self::constraint($previous)],
            $failure instanceof InvalidCookies => [self::parameter('cookie', $previous) => self::constraint($previous)],
            $failure instanceof InvalidPath => [self::parameter('path', $previous) => self::constraint($previous)],
            default => ['request' => self::constraint($previous)],
        };
    }

    /**
     * Field position inside a structured body, from the validator's breadcrumb.
     */
    private static function path(string $root, ?\Throwable $previous): string
    {
        if (!$previous instanceof SchemaMismatch) {
            return $root;
        }

        $crumb = $previous->dataBreadCrumb();

        if ($crumb === null) {
            return $root;
        }

        $segments = [];

        /** @var mixed $key */
        foreach ($crumb->buildChain() as $key) {
            if (is_string($key) || is_int($key)) {
                $segments[] = (string) $key;
            }
        }

        return $segments === [] ? $root : $root . '.' . implode('.', $segments);
    }

    /**
     * Name of the offending parameter. The validator keeps it on the inner
     * exception; the outer message has it too, but with the value spliced in.
     */
    private static function parameter(string $root, ?\Throwable $previous): string
    {
        if ($previous instanceof RequiredParameterMissing || $previous instanceof InvalidParameter) {
            return $root . '.' . $previous->name();
        }

        return self::path($root, $previous);
    }

    private static function constraint(?\Throwable $previous): string
    {
        if ($previous instanceof RequiredParameterMissing) {
            return 'required';
        }

        if ($previous instanceof InvalidParameter) {
            return self::constraint($previous->getPrevious());
        }

        return match (true) {
            // Covers TypeMismatch, FormatMismatch, NotEnoughValidSchemas and
            // TooManyValidSchemas as well — they all carry the keyword that
            // failed, which is exactly the vocabulary we want to hand out.
            $previous instanceof KeywordMismatch => $previous->keyword(),
            $previous instanceof ContentTypeMismatch => 'content_type',
            $previous instanceof SchemaMismatch => 'schema',
            default => 'invalid',
        };
    }

    private function requestValidator(): ServerRequestValidator
    {
        return $this->requests ??= (new ValidatorBuilder())
            ->fromSchema($this->artefacts->specification())
            ->getServerRequestValidator();
    }

    private function responseValidator(): ResponseValidator
    {
        return $this->responses ??= (new ValidatorBuilder())
            ->fromSchema($this->artefacts->specification())
            ->getResponseValidator();
    }

    private function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}

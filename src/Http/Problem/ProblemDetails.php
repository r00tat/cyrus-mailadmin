<?php

declare(strict_types=1);

namespace Mailadmin\Http\Problem;

use JsonSerializable;

/**
 * An `application/problem+json` document (RFC 9457, architecture 7.1).
 *
 * Every error the API returns has this shape. Two additions to the RFC members:
 *
 *  - `code` — the stable, machine-readable error code the frontend translates
 *    (E-16). A translated `title` would be neither testable nor usable by
 *    another client.
 *  - `request_id` — the same value as the `X-Request-Id` header, so that a user
 *    reporting an error hands over something that can be found in the log.
 *
 * `detail` never carries an exception message. That rule lives in
 * {@see \Mailadmin\Http\Middleware\ErrorHandlerMiddleware}, but it is the reason
 * this class has no constructor taking a Throwable.
 */
final readonly class ProblemDetails implements JsonSerializable
{
    /**
     * @param array<string, string> $errors field name => message, for validation failures
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $title,
        public ?string $detail = null,
        public ?string $instance = null,
        public ?string $requestId = null,
        public array $errors = [],
        public string $type = 'about:blank',
    ) {
    }

    public static function internalServerError(?string $instance = null, ?string $requestId = null): self
    {
        return new self(
            status: 500,
            code: ErrorCode::INTERNAL_ERROR,
            title: 'Internal Server Error',
            detail: 'The request could not be processed. The incident has been logged.',
            instance: $instance,
            requestId: $requestId,
        );
    }

    public static function notFound(?string $instance = null, ?string $requestId = null): self
    {
        return new self(
            status: 404,
            code: ErrorCode::NOT_FOUND,
            title: 'Not Found',
            detail: 'No resource matches this path and method.',
            instance: $instance,
            requestId: $requestId,
        );
    }

    public function withRequestId(?string $requestId): self
    {
        return new self(
            status: $this->status,
            code: $this->code,
            title: $this->title,
            detail: $this->detail,
            instance: $this->instance,
            requestId: $requestId,
            errors: $this->errors,
            type: $this->type,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $document = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'code' => $this->code,
        ];

        if ($this->detail !== null) {
            $document['detail'] = $this->detail;
        }

        if ($this->instance !== null) {
            $document['instance'] = $this->instance;
        }

        if ($this->requestId !== null) {
            $document['request_id'] = $this->requestId;
        }

        if ($this->errors !== []) {
            $document['errors'] = $this->errors;
        }

        return $document;
    }

    public function toJson(): string
    {
        // JSON_THROW_ON_ERROR is deliberate: a problem document that cannot be
        // encoded must not degrade into an empty body. The array only ever holds
        // scalars, so this cannot fire in practice.
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

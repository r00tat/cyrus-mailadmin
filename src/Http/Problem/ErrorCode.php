<?php

declare(strict_types=1);

namespace Mailadmin\Http\Problem;

/**
 * The cross-cutting error codes the framework layer itself produces.
 *
 * Codes follow `<resource>.<state>` in `snake_case` (U-7). Everything that is
 * not tied to a resource carries the `request` prefix. The **authoritative**
 * list is the `ErrorCode` enum in the OpenAPI contract
 * (`openapi/components/common.yaml`) — the constants here exist so that the
 * middleware chain, which runs before and outside the contract, does not spell
 * them as literals.
 *
 * A contract test asserts that every constant below appears in that enum, so
 * the two cannot drift: adding a constant without adding the enum entry fails
 * the suite. Resource-specific codes (`domain.not_found`,
 * `account.quota_exceeded`) do **not** belong here — they go in the enum and in
 * the package that raises them.
 *
 * The code, not the text, is the contract (E-16): the frontend translates on the
 * code, `title` and `detail` stay English fallbacks.
 */
final class ErrorCode
{
    /** An unhandled exception. Nothing about it reaches the client. */
    public const string INTERNAL_ERROR = 'request.internal_error';

    /** No route matches. */
    public const string NOT_FOUND = 'request.not_found';

    /** The path exists, the method does not. The response carries `Allow`. */
    public const string METHOD_NOT_ALLOWED = 'request.method_not_allowed';

    /** The body claimed to be JSON but could not be decoded. */
    public const string MALFORMED_JSON = 'request.malformed_json';

    /** A body was sent with a content type the API does not accept. */
    public const string UNSUPPORTED_MEDIA_TYPE = 'request.unsupported_media_type';

    /** The request does not satisfy the contract. Accompanied by an `errors` map. */
    public const string VALIDATION_FAILED = 'request.validation_failed';

    /** No token, an expired token, or one that does not verify. */
    public const string UNAUTHENTICATED = 'request.unauthenticated';

    /**
     * Authenticated, but the role does not carry the operation's permission (S3).
     *
     * Never used for an object the actor may not see — that is {@see NOT_FOUND},
     * because `403` on a specific object would confirm that it exists.
     */
    public const string FORBIDDEN = 'request.forbidden';

    /** The `If-Match` value does not match the current representation (U-19). */
    public const string PRECONDITION_FAILED = 'request.precondition_failed';

    /** Rate limit reached (architecture 5.6). */
    public const string RATE_LIMITED = 'request.rate_limited';

    /**
     * The build artefacts do not match the contract next to them (U-8).
     *
     * `migration` rather than `request`, because it is not the request that is
     * wrong: the deployment is incomplete. Same prefix as the schema-version
     * mismatch F3 reports, and for the same reason.
     */
    public const string BUILD_STALE = 'migration.build_stale';

    private function __construct()
    {
    }

    /**
     * Every code this class declares — what the contract test compares against
     * the `ErrorCode` enum in the contract.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        /** @var array<string, string> $constants */
        $constants = (new \ReflectionClass(self::class))->getConstants();

        return array_values($constants);
    }
}

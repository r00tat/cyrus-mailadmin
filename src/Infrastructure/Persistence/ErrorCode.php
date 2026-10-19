<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

/**
 * The stable error codes the persistence layer raises (U-7).
 *
 * Only one of them can ever reach an HTTP client: {@see SCHEMA_OUTDATED}, which
 * {@see \Mailadmin\Infrastructure\Persistence\Guard\SchemaVersionMiddleware}
 * turns into a `503`. It therefore has to appear in the `ErrorCode` enum of the
 * contract (`openapi/components/common.yaml`) — that file belongs to F2, so F3
 * asks for the entry rather than adding it.
 *
 * The remaining codes are internal: they identify a failure in a log line and in
 * the exit status of a command. Where such a failure surfaces over HTTP it does
 * so as `request.service_unavailable` or `request.internal_error` — the client
 * learns that the request failed, never why the database disagreed.
 *
 * The `migration` prefix rather than `database` is deliberate: U-7 enumerates
 * the permitted resource prefixes and `database` is not among them. F2 already
 * uses `migration.build_stale` for the same class of problem — the deployment,
 * not the request, is wrong.
 */
final class ErrorCode
{
    /**
     * The schema is behind the migrations shipped with this build.
     *
     * **The only code here that is part of the API contract.**
     */
    public const string SCHEMA_OUTDATED = 'migration.schema_outdated';

    /** No connection to the database. */
    public const string UNAVAILABLE = 'migration.database_unavailable';

    /** A statement failed. The driver message is logged, never returned. */
    public const string QUERY_FAILED = 'migration.query_failed';

    /** A migration file is not readable, misnamed or numbered twice. */
    public const string MIGRATION_INVALID = 'migration.invalid';

    /** A migration violates the additive-only rule (E-1, CLAUDE.md rule 1). */
    public const string MIGRATION_REJECTED = 'migration.rejected';

    /** A migration statement failed while running. */
    public const string MIGRATION_FAILED = 'migration.failed';

    /** Another process holds the migration lock. */
    public const string MIGRATION_LOCKED = 'migration.locked';

    /** A sort field, filter field or page value outside what the repository allows. */
    public const string CRITERIA_INVALID = 'request.validation_failed';

    /** A transaction was used in a way that would hide its boundary. */
    public const string TRANSACTION_MISUSE = 'migration.transaction_misuse';

    /** The database configuration is incomplete or malformed. */
    public const string CONFIGURATION_INVALID = 'migration.configuration_invalid';

    private function __construct()
    {
    }
}

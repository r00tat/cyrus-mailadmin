<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;

/**
 * One thing that happened, ready to be written.
 *
 * **This is the interface the other packages use.** A use case injects
 * {@see AuditWriter}, builds one of these and hands it over — one line per
 * operation, no HTTP in sight:
 *
 * ```php
 * $this->audit->record(AuditEntry::creation(
 *     $actor, 'domain.create', 'domain', $name->toString(),
 *     after: $representation,
 *     domain: $name,
 * ));
 * ```
 *
 * The request id and the client address are **not** parameters. They are facts
 * about the request, not about the operation, and threading them through every
 * use case signature would put HTTP into the application layer for no gain; the
 * writer reads them from {@see AuditRequestContext} instead (U-64).
 *
 * ## Before and after
 *
 * A creation has no `before` and a deletion no `after` — both are `null` rather
 * than an empty array, so that "did not exist" stays distinguishable from "was
 * empty" (U-61). The named constructors enforce that; there is no way to build a
 * creation that claims a previous state.
 *
 * What goes in is the **representation the API shows**, not the database row:
 * the same fields, so that an entry can be read next to a `GET` response, and no
 * columns webcyradm writes in parallel. Secrets do not have to be removed by the
 * caller — {@see Redactor} runs inside the writer, and it runs whether the caller
 * remembered or not.
 *
 * ## Refusals and failures
 *
 * {@see refusal()} is for an attempt that was not allowed, {@see failure()} for
 * one that was allowed and did not work. Neither carries an `after`, because
 * nothing changed. The `reason` is for an operator reading the trail, never for a
 * client: it is not translated and not part of any contract.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function __construct(
        public AuditActor $actor,
        public AuditAction $action,
        public string $objectType,
        public string $objectId,
        public ?string $domain,
        public AuditOutcome $outcome,
        public string $reason,
        public ?array $before,
        public ?array $after,
        public ?AuditEntryId $parent = null,
    ) {
    }

    /**
     * An object that did not exist and now does.
     *
     * @param array<string, mixed> $after
     *
     * @throws InvalidAuditEntry
     */
    public static function creation(
        ActorContext|AuditActor $actor,
        string $action,
        string $objectType,
        ?string $objectId = null,
        array $after = [],
        DomainName|string|null $domain = null,
    ): self {
        return new self(
            self::actor($actor),
            AuditAction::of($action),
            self::objectType($objectType),
            self::objectId($objectId),
            self::domain($domain),
            AuditOutcome::Succeeded,
            '',
            null,
            $after,
        );
    }

    /**
     * An object that existed before and after.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     *
     * @throws InvalidAuditEntry
     */
    public static function change(
        ActorContext|AuditActor $actor,
        string $action,
        string $objectType,
        ?string $objectId = null,
        ?array $before = null,
        ?array $after = null,
        DomainName|string|null $domain = null,
    ): self {
        return new self(
            self::actor($actor),
            AuditAction::of($action),
            self::objectType($objectType),
            self::objectId($objectId),
            self::domain($domain),
            AuditOutcome::Succeeded,
            '',
            $before,
            $after,
        );
    }

    /**
     * An object that existed and no longer does.
     *
     * @param array<string, mixed> $before
     *
     * @throws InvalidAuditEntry
     */
    public static function removal(
        ActorContext|AuditActor $actor,
        string $action,
        string $objectType,
        ?string $objectId = null,
        array $before = [],
        DomainName|string|null $domain = null,
    ): self {
        return new self(
            self::actor($actor),
            AuditAction::of($action),
            self::objectType($objectType),
            self::objectId($objectId),
            self::domain($domain),
            AuditOutcome::Succeeded,
            '',
            $before,
            null,
        );
    }

    /**
     * An attempt that was not allowed. Nothing changed.
     *
     * @throws InvalidAuditEntry
     */
    public static function refusal(
        ActorContext|AuditActor $actor,
        string $action,
        string $objectType,
        ?string $objectId = null,
        string $reason = '',
        DomainName|string|null $domain = null,
    ): self {
        return new self(
            self::actor($actor),
            AuditAction::of($action),
            self::objectType($objectType),
            self::objectId($objectId),
            self::domain($domain),
            AuditOutcome::Denied,
            self::reason($reason),
            null,
            null,
        );
    }

    /**
     * An attempt that was allowed and did not work.
     *
     * @param array<string, mixed>|null $before what the object looked like before the attempt
     *
     * @throws InvalidAuditEntry
     */
    public static function failure(
        ActorContext|AuditActor $actor,
        string $action,
        string $objectType,
        ?string $objectId = null,
        string $reason = '',
        ?array $before = null,
        DomainName|string|null $domain = null,
    ): self {
        return new self(
            self::actor($actor),
            AuditAction::of($action),
            self::objectType($objectType),
            self::objectId($objectId),
            self::domain($domain),
            AuditOutcome::Failed,
            self::reason($reason),
            $before,
            null,
        );
    }

    /**
     * The same entry, recorded as part of a larger operation.
     */
    public function partOf(AuditEntryId $operation): self
    {
        return new self(
            $this->actor,
            $this->action,
            $this->objectType,
            $this->objectId,
            $this->domain,
            $this->outcome,
            $this->reason,
            $this->before,
            $this->after,
            $operation,
        );
    }

    private static function actor(ActorContext|AuditActor $actor): AuditActor
    {
        return $actor instanceof AuditActor ? $actor : AuditActor::of($actor);
    }

    /**
     * @throws InvalidAuditEntry
     */
    private static function objectType(string $type): string
    {
        $name = trim($type);

        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) !== 1) {
            throw InvalidAuditEntry::malformedObjectType($type);
        }

        return $name;
    }

    /**
     * The identifier is truncated rather than refused: it comes from the object,
     * a mailbox identity can be long, and losing the tail of one is a much
     * smaller loss than losing the entry.
     */
    private static function objectId(?string $objectId): string
    {
        $id = trim($objectId ?? '');

        return strlen($id) <= 320 ? $id : substr($id, 0, 320);
    }

    private static function domain(DomainName|string|null $domain): ?string
    {
        $name = $domain instanceof DomainName ? $domain->toString() : $domain;

        return $name === null || trim($name) === '' ? null : trim($name);
    }

    /**
     * Collapsed to one line and cut to what the column holds. A reason with a
     * newline in it would break a log-shaped reading of the table, and a reason
     * long enough to be truncated by the database is one that was a sentence
     * when it should have been a keyword.
     */
    private static function reason(string $reason): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $reason));

        return strlen($collapsed) <= 190 ? $collapsed : substr($collapsed, 0, 190);
    }
}

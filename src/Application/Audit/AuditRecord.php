<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

use DateTimeImmutable;
use Mailadmin\Domain\Model\AdminRole;

/**
 * An entry as it comes back out of the table.
 *
 * Separate from {@see AuditEntry} because they are not the same thing: an entry
 * is what a use case wants recorded, a record is what was recorded — with an id,
 * a timestamp, an address and a request id the use case never supplied. Folding
 * the two into one class would mean a write path carrying four fields it must
 * not fill and a read path carrying named constructors it cannot use.
 *
 * The state is what {@see Redactor} left of it. There is no unredacted form
 * anywhere: the masking happens before the insert, not on the way out, so a
 * direct `SELECT` by an operator sees the same thing the API does.
 */
final readonly class AuditRecord
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        public int $id,
        public DateTimeImmutable $recordedAt,
        public string $actor,
        public ?AdminRole $actorRole,
        public string $clientIp,
        public string $requestId,
        public string $action,
        public string $objectType,
        public string $objectId,
        public ?string $domain,
        public AuditOutcome $outcome,
        public string $reason,
        public ?array $before,
        public ?array $after,
        public ?int $parentId,
    ) {
    }

    /**
     * Exactly the shape of `AuditEntry` in the contract.
     *
     * `object_id`, `reason` and `client_ip` are `null` rather than `''` when
     * they are absent: the column cannot hold a null (it has a default), but the
     * API should not tell a client that an entry has an object whose identifier
     * is the empty string.
     *
     * @return array<string, mixed>
     */
    public function toRepresentation(): array
    {
        return [
            'id' => $this->id,
            'recorded_at' => $this->recordedAt->format('Y-m-d\TH:i:s\Z'),
            'actor' => $this->actor,
            'actor_role' => $this->actorRole?->value,
            'client_ip' => self::orNull($this->clientIp),
            'request_id' => self::orNull($this->requestId),
            'action' => $this->action,
            'object_type' => $this->objectType,
            'object_id' => self::orNull($this->objectId),
            'domain' => $this->domain,
            'outcome' => $this->outcome->value,
            'reason' => self::orNull($this->reason),
            'before' => $this->before,
            'after' => $this->after,
            'part_of' => $this->parentId,
        ];
    }

    private static function orNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}

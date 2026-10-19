<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Audit\AuditReader;
use Mailadmin\Application\Audit\AuditRecord;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;

/**
 * Reading `mailadmin_audit`.
 *
 * The only reader of the table, and a read-only one: nothing here inserts, and
 * nothing anywhere updates or deletes a row. An audit trail one can edit through
 * the application is not evidence of anything.
 *
 * ## The allowlists are the contract
 *
 * {@see sortable()} and {@see filterable()} are the SQL side of what
 * `openapi/paths/audit.yaml` declares; `AuditEndpointTest` compares the two, so
 * a `sort` value the contract offers and this class does not is a failing test
 * rather than a `500` in front of somebody.
 *
 * `id` is the tiebreaker on every ordering. Timestamps have second resolution
 * and a batch writes dozens of rows within one, so ordering by `recorded_at`
 * alone would let a row appear on two pages while nothing changed.
 *
 * ## Scoped, although it narrows nothing today
 *
 * `audit.read` is superuser-only (U-43), so {@see Repository::scopedTo()} is a
 * no-op for every actor that can reach this class. It is called all the same:
 * the day the permission is widened, the narrowing is already in place and
 * already tested, which is the opposite of finding out afterwards. Entries with
 * no domain — a sign-in, a maintenance run — are then invisible to a domain
 * master, which is the correct answer for a row that is not about their domains.
 */
final class AuditRepository extends Repository implements AuditReader
{
    protected function sortable(): SortAllowlist
    {
        return new SortAllowlist(
            [
                'recorded_at' => 'a.recorded_at',
                'actor' => 'a.actor',
                'action' => 'a.action',
                'object' => 'a.object_type',
                'id' => 'a.id',
            ],
            default: 'recorded_at',
            tiebreaker: 'id',
        );
    }

    protected function filterable(): FilterAllowlist
    {
        return new FilterAllowlist(
            new FilterField('actor', 'a.actor'),
            // A prefix rather than an exact match, so that `action=domain.`
            // answers "everything that happened to domains" — the question an
            // operator actually asks. An exact action still matches itself.
            new FilterField('action', 'a.action', FilterComparison::StartsWith),
            new FilterField('object_type', 'a.object_type'),
            new FilterField('object_id', 'a.object_id'),
            new FilterField('domain', 'a.domain_name'),
            new FilterField('outcome', 'a.outcome'),
            new FilterField('request_id', 'a.request_id'),
            new FilterField('part_of', 'a.parent_id'),
            new FilterField('since', 'a.recorded_at', FilterComparison::GreaterOrEqual),
            new FilterField('until', 'a.recorded_at', FilterComparison::LessOrEqual),
        );
    }

    /**
     * @return Page<AuditRecord>
     */
    public function list(ActorContext $actor, ListCriteria $criteria): Page
    {
        $query = new ListQuery(
            select: 'a.id, a.recorded_at, a.actor, a.actor_role, a.client_ip, a.request_id, '
                . 'a.action, a.object_type, a.object_id, a.domain_name, a.outcome, a.reason, '
                . 'a.state_before, a.state_after, a.parent_id',
            from: '`mailadmin_audit` a',
        );

        return $this->fetchPage(
            $this->scopedTo($query, $actor, 'a.domain_name'),
            $criteria,
            self::toRecord(...),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toRecord(array $row): AuditRecord
    {
        return new AuditRecord(
            id: (int) self::text($row['id'] ?? null),
            recordedAt: self::moment(self::text($row['recorded_at'] ?? null)),
            actor: self::text($row['actor'] ?? null),
            actorRole: AdminRole::tryFrom(self::text($row['actor_role'] ?? null)),
            clientIp: self::text($row['client_ip'] ?? null),
            requestId: self::text($row['request_id'] ?? null),
            action: self::text($row['action'] ?? null),
            objectType: self::text($row['object_type'] ?? null),
            objectId: self::text($row['object_id'] ?? null),
            domain: isset($row['domain_name']) && is_scalar($row['domain_name'])
                ? (string) $row['domain_name']
                : null,
            // An unknown value cannot occur — the column is written from the
            // enum — but a row put there by hand should not take the endpoint
            // down. It is reported as a failure, which is the reading that
            // overstates nothing.
            outcome: AuditOutcome::tryFrom(self::text($row['outcome'] ?? null)) ?? AuditOutcome::Failed,
            reason: self::text($row['reason'] ?? null),
            before: self::decode($row['state_before'] ?? null),
            after: self::decode($row['state_after'] ?? null),
            parentId: isset($row['parent_id']) && is_numeric($row['parent_id'])
                ? (int) $row['parent_id']
                : null,
        );
    }

    /**
     * The column holds a JSON object written by {@see SqlAuditWriter}. Anything
     * else — a `NULL`, or something a third party put there — reads as "no
     * state", never as a partial one.
     *
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The column is UTC (U-14) and carries no zone, so the zone is supplied
     * here. Reading it as local time would shift every entry by the server's
     * offset, which is the kind of error that only shows up in an investigation.
     */
    private static function moment(string $stamp): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stamp, $utc)
            ?: new DateTimeImmutable('@0');
    }
}

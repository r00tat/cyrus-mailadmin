<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditEntryId;
use Mailadmin\Application\Audit\AuditReader;
use Mailadmin\Application\Audit\AuditRecord;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Audit\Redactor;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Runtime\Clock;
use Mailadmin\Runtime\SystemClock;

/**
 * The audit trail without a database — **the double the A-packages test
 * against**.
 *
 * It implements both halves on purpose. A test that writes through the port and
 * reads back through the same object can assert what the trail *says*, which is
 * the thing worth asserting; two separate doubles would let a test pass while
 * the writer recorded something the reader could never find.
 *
 * ```php
 * $audit = new InMemoryAuditTrail();
 * $useCase = new CreateDomain($repository, $audit);
 *
 * $useCase->handle($actor, …);
 *
 * self::assertSame('domain.create', $audit->last()->action);
 * self::assertArrayNotHasKey('password', (array) $audit->last()->after);
 * ```
 *
 * **The redaction is real.** It runs the same {@see Redactor} the SQL writer
 * runs, so a test can prove that a secret never reaches the trail — which is
 * S4's definition of done, and a claim a double that stored the entry verbatim
 * could not support.
 *
 * ## What it does not imitate
 *
 * Filtering and ordering are honest but simple: the fields the contract offers,
 * compared in PHP. It is not a SQL engine and does not try to be — the statement
 * itself is what {@see AuditRepository} and its integration test are for. It is
 * **not wired into the container** (U-37): a container that could be talked into
 * an in-memory audit trail by an environment variable would be one variable away
 * from an installation that records nothing.
 */
final class InMemoryAuditTrail implements AuditWriter, AuditReader
{
    /** @var list<AuditRecord> */
    private array $records = [];

    private int $nextId = 1;

    private readonly Redactor $redactor;

    private readonly Clock $clock;

    private readonly AuditRequestContext $context;

    public function __construct(
        ?Redactor $redactor = null,
        ?Clock $clock = null,
        ?AuditRequestContext $context = null,
    ) {
        $this->redactor = $redactor ?? new Redactor();
        $this->clock = $clock ?? new SystemClock();
        $this->context = $context ?? new AuditRequestContext();
    }

    public function record(AuditEntry $entry): AuditEntryId
    {
        $id = $this->nextId++;

        $this->records[] = new AuditRecord(
            id: $id,
            recordedAt: $this->clock->now(),
            actor: $entry->actor->username,
            actorRole: $entry->actor->role,
            clientIp: $this->context->clientIp(),
            requestId: $this->context->requestId(),
            action: $entry->action->value,
            objectType: $entry->objectType,
            objectId: $entry->objectId,
            domain: $entry->domain,
            outcome: $entry->outcome,
            reason: $entry->reason,
            before: $this->redactor->apply($entry->before),
            after: $this->redactor->apply($entry->after),
            parentId: $entry->parent?->value,
        );

        return AuditEntryId::of($id);
    }

    /**
     * @return Page<AuditRecord>
     */
    public function list(ActorContext $actor, ListCriteria $criteria): Page
    {
        $matching = array_values(array_filter(
            $this->records,
            fn (AuditRecord $record): bool => self::visibleTo($record, $actor)
                && $this->matches($record, $criteria),
        ));

        usort($matching, self::comparator($criteria));

        return Page::of(
            array_slice($matching, $criteria->offset(), $criteria->perPage),
            $criteria,
            count($matching),
        );
    }

    // -----------------------------------------------------------------
    // What a test asks
    // -----------------------------------------------------------------

    /**
     * @return list<AuditRecord>
     */
    public function all(): array
    {
        return $this->records;
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * The most recent entry, or `null` when nothing was recorded.
     */
    public function last(): ?AuditRecord
    {
        return $this->records === [] ? null : $this->records[count($this->records) - 1];
    }

    /**
     * @return list<AuditRecord>
     */
    public function withAction(string $action): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (AuditRecord $record): bool => $record->action === $action,
        ));
    }

    public function forget(): void
    {
        $this->records = [];
        $this->nextId = 1;
    }

    // -----------------------------------------------------------------

    private static function visibleTo(AuditRecord $record, ActorContext $actor): bool
    {
        if (!$actor->requiresDomainScope()) {
            return true;
        }

        // The same rule `Repository::scopedTo()` produces in SQL: an entry
        // without a domain belongs to nobody's scope.
        return $record->domain !== null
            && in_array($record->domain, $actor->scope->domainNames(), true);
    }

    private function matches(AuditRecord $record, ListCriteria $criteria): bool
    {
        /** @var mixed $value */
        foreach ($criteria->filters as $key => $value) {
            if (!is_scalar($value)) {
                return false;
            }

            $wanted = (string) $value;

            $matched = match ($key) {
                'actor' => $record->actor === $wanted,
                'action' => str_starts_with($record->action, $wanted),
                'object_type' => $record->objectType === $wanted,
                'object_id' => $record->objectId === $wanted,
                'domain' => $record->domain === $wanted,
                'outcome' => $record->outcome->value === $wanted,
                'request_id' => $record->requestId === $wanted,
                'part_of' => (string) $record->parentId === $wanted,
                'since' => $record->recordedAt >= self::moment($wanted),
                'until' => $record->recordedAt <= self::moment($wanted),
                default => false,
            };

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return callable(AuditRecord, AuditRecord): int
     */
    private static function comparator(ListCriteria $criteria): callable
    {
        $field = $criteria->sort ?? 'recorded_at';
        $sign = $criteria->order === SortDirection::Descending ? -1 : 1;

        return static function (AuditRecord $left, AuditRecord $right) use ($field, $sign): int {
            $comparison = match ($field) {
                'actor' => strcmp($left->actor, $right->actor),
                'action' => strcmp($left->action, $right->action),
                'object' => strcmp($left->objectType, $right->objectType),
                'id' => $left->id <=> $right->id,
                default => $left->recordedAt <=> $right->recordedAt,
            };

            // The same tiebreaker the repository applies, so that a test written
            // against the double describes what the database will do.
            return $sign * ($comparison !== 0 ? $comparison : $left->id <=> $right->id);
        };
    }

    private static function moment(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return new DateTimeImmutable('@0');
        }
    }
}

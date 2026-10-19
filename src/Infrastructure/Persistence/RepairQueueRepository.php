<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Maintenance\RepairEntry;
use Mailadmin\Application\Maintenance\RepairLedger;
use Mailadmin\Application\Maintenance\RepairResolution;
use Mailadmin\Application\Maintenance\RepairStatus;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use Mailadmin\Infrastructure\Repair\LoggingRepairQueue;
use Mailadmin\Runtime\Clock;
use Throwable;

/**
 * `mailadmin_repair_queue` — the table that replaces the log-only stopgap of
 * U-50 (A6, U-117).
 *
 * ## Its own connection
 *
 * The {@see Connection} this repository gets is **not** the one the rest of the
 * request uses; `config/di/maintenance.php` builds a second one from the same
 * source. A repair entry is written when an operation has failed, and often from
 * inside a transaction that is about to be rolled back — on the shared
 * connection the entry would roll back with it, and the one record of what is
 * left behind would vanish with the operation that left it. The second
 * connection is opened only when an entry is written or the queue is read.
 *
 * ## Recording never fails the caller
 *
 * {@see record()} swallows every error, as the port demands, and hands the entry
 * to {@see LoggingRepairQueue} instead — the database is quite often the thing
 * that failed in the first place, and a `critical` log line is then the best
 * record there is.
 *
 * ## The allowlists are the contract
 *
 * {@see sortable()} and {@see filterable()} match the `RepairSort` enum and the
 * filter parameters of `openapi/paths/maintenance.yaml`; `MaintenanceEndpointTest`
 * compares the two.
 */
final class RepairQueueRepository extends Repository implements RepairQueue, RepairLedger
{
    private const string COLUMNS = 'r.id, r.recorded_at, r.operation, r.step, r.subject, r.intended, '
        . 'r.reached, r.reason, r.parameters, r.request_id, r.status, r.attempts, r.last_attempt_at, '
        . 'r.last_error, r.resolved_at, r.resolved_by, r.resolution, r.note';

    /** Server-wide `GET_LOCK` names are at most 64 characters; an id is at most 20 digits. */
    public const string LOCK_PREFIX = 'mailadmin.repair.';

    public function __construct(
        Connection $connection,
        private readonly Clock $clock,
        private readonly AuditRequestContext $context,
        private readonly LoggingRepairQueue $fallback,
    ) {
        parent::__construct($connection);
    }

    /**
     * `id` is the default, not `recorded_at`: ids are handed out in insertion
     * order, and the tiebreaker of {@see \Mailadmin\Infrastructure\Persistence\Criteria\OrderBy} is always ascending, so
     * "newest first" by a second-resolution timestamp would list the entries
     * of one second oldest first.
     */
    protected function sortable(): SortAllowlist
    {
        return new SortAllowlist(
            [
                'recorded_at' => 'r.recorded_at',
                'operation' => 'r.operation',
                'id' => 'r.id',
            ],
            default: 'id',
            tiebreaker: 'id',
        );
    }

    protected function filterable(): FilterAllowlist
    {
        return new FilterAllowlist(
            new FilterField('status', 'r.status'),
            // A prefix, so that `operation=account.` answers "everything A2 left".
            new FilterField('operation', 'r.operation', FilterComparison::StartsWith),
            new FilterField('step', 'r.step'),
        );
    }

    public function record(RepairRequest $request): void
    {
        try {
            $this->connection->execute(
                'INSERT INTO `mailadmin_repair_queue` ('
                . '`recorded_at`, `operation`, `step`, `subject`, `intended`, `reached`, `reason`, '
                . '`parameters`, `request_id`, `status`'
                . ') VALUES ('
                . ':recorded_at, :operation, :step, :subject, :intended, :reached, :reason, '
                . ':parameters, :request_id, :status'
                . ')',
                [
                    'recorded_at' => self::column($this->clock->now()),
                    'operation' => self::clip($request->operation, 64),
                    'step' => self::clip($request->step, 64),
                    'subject' => self::clip($request->subject, 320),
                    'intended' => $request->intended,
                    'reached' => $request->reached,
                    'reason' => self::clip($request->reason, 190),
                    'parameters' => $request->parameters === [] ? null : json_encode(
                        $request->parameters,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        | JSON_INVALID_UTF8_SUBSTITUTE,
                    ),
                    'request_id' => $this->context->requestId(),
                    'status' => RepairStatus::Open->value,
                ],
            );
        } catch (Throwable) {
            // The port promises not to fail the caller. The log line keeps the
            // whole entry; the error itself is not added — it may quote SQL.
            $this->fallback->record($request);
        }
    }

    public function list(ListCriteria $criteria): Page
    {
        return $this->fetchPage(
            new ListQuery(select: self::COLUMNS, from: '`mailadmin_repair_queue` r'),
            $criteria,
            self::toEntry(...),
        );
    }

    public function find(int $id): ?RepairEntry
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM `mailadmin_repair_queue` r WHERE r.id = :id',
            ['id' => $id],
        );

        return $row === null ? null : self::toEntry($row);
    }

    /**
     * A `GET_LOCK` on the queue's own connection, like the account and
     * namespace locks (U-81, U-97). The entry's row is InnoDB and could be
     * locked with `SELECT … FOR UPDATE`, but that would hold a transaction
     * open on this connection for the length of a Cyrus operation — and an
     * entry another operation records meanwhile would wait for its commit.
     */
    public function withEntryLock(int $id, callable $work): mixed
    {
        return NamedLock::hold(
            $this->connection,
            self::LOCK_PREFIX . $id,
            sprintf('lock of repair entry %d', $id),
            $work,
        );
    }

    public function recordAttempt(int $id, string $errorCode, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE `mailadmin_repair_queue` SET `attempts` = `attempts` + 1, '
            . '`last_attempt_at` = :at, `last_error` = :error WHERE `id` = :id AND `status` = :open',
            [
                'at' => self::column($at),
                'error' => self::clip($errorCode, 64),
                'id' => $id,
                'open' => RepairStatus::Open->value,
            ],
        );
    }

    public function resolve(
        int $id,
        RepairResolution $resolution,
        string $resolvedBy,
        string $note,
        DateTimeImmutable $at,
    ): bool {
        $changed = $this->connection->execute(
            'UPDATE `mailadmin_repair_queue` SET `status` = :resolved, `resolution` = :resolution, '
            . '`resolved_at` = :at, `resolved_by` = :by, `note` = :note '
            . 'WHERE `id` = :id AND `status` = :open',
            [
                'resolved' => RepairStatus::Resolved->value,
                'resolution' => $resolution->value,
                'at' => self::column($at),
                'by' => self::clip($resolvedBy, 190),
                'note' => self::clip($note, 500),
                'id' => $id,
                'open' => RepairStatus::Open->value,
            ],
        );

        return $changed > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toEntry(array $row): RepairEntry
    {
        return new RepairEntry(
            id: (int) self::text($row['id'] ?? null),
            recordedAt: self::moment(self::text($row['recorded_at'] ?? null)) ?? new DateTimeImmutable('@0'),
            operation: self::text($row['operation'] ?? null),
            step: self::text($row['step'] ?? null),
            subject: self::text($row['subject'] ?? null),
            intended: self::text($row['intended'] ?? null),
            reached: self::text($row['reached'] ?? null),
            reason: self::text($row['reason'] ?? null),
            parameters: self::parameters($row['parameters'] ?? null),
            requestId: self::text($row['request_id'] ?? null),
            // A value put there by hand reads as open: an entry that still
            // needs somebody is the reading that hides nothing.
            status: RepairStatus::tryFrom(self::text($row['status'] ?? null)) ?? RepairStatus::Open,
            attempts: (int) self::text($row['attempts'] ?? null),
            lastAttemptAt: self::moment(self::text($row['last_attempt_at'] ?? null)),
            lastError: self::text($row['last_error'] ?? null),
            resolvedAt: self::moment(self::text($row['resolved_at'] ?? null)),
            resolvedBy: self::text($row['resolved_by'] ?? null),
            resolution: RepairResolution::tryFrom(self::text($row['resolution'] ?? null)),
            note: self::text($row['note'] ?? null),
        );
    }

    /**
     * A JSON object of strings, or nothing. Anything else in the column — a
     * hand-written value, a nested structure — reads as "no parameters", which
     * makes the entry manual rather than retryable on a guess.
     *
     * @return array<string, string>
     */
    private static function parameters(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            return [];
        }

        $parameters = [];

        /** @var mixed $item */
        foreach ($decoded as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                return [];
            }

            $parameters[$key] = $item;
        }

        return $parameters;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function column(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * UTC, like every timestamp column of ours (U-14).
     */
    private static function moment(string $stamp): ?DateTimeImmutable
    {
        if ($stamp === '') {
            return null;
        }

        return DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stamp, new DateTimeZone('UTC')) ?: null;
    }

    /**
     * Cut to the column width, by character. The prose fields are ours and
     * short; a subject longer than 320 characters is not, and an entry with a
     * shortened subject is better than no entry.
     */
    private static function clip(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}

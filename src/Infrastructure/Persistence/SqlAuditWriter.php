<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeZone;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditEntryId;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Audit\Redactor;
use Mailadmin\Runtime\Clock;

/**
 * `mailadmin_audit`, one prepared `INSERT`.
 *
 * Everything interesting has already happened by the time this class runs: the
 * entry was built by the use case, the secrets were removed by {@see Redactor},
 * and the address and request id came from {@see AuditRequestContext}. What is
 * left is the statement — which is the point, because a writer with logic in it
 * would be a writer that can decide not to write.
 *
 * ## Three things it does not do
 *
 *  - **It does not open a transaction.** Whether the entry commits with the
 *    change is the caller's decision and has to be (U-63): inside the operation's
 *    transaction for a success, outside it for a refusal.
 *  - **It does not swallow a failure.** {@see PersistenceError} passes through.
 *    Called where it belongs, that rolls the change back with it.
 *  - **It does not redact twice.** Redaction happens here and only here, so
 *    there is exactly one place that can be wrong about it and exactly one place
 *    to test.
 */
final readonly class SqlAuditWriter implements AuditWriter
{
    public function __construct(
        private Connection $connection,
        private Redactor $redactor,
        private Clock $clock,
        private AuditRequestContext $context,
    ) {
    }

    public function record(AuditEntry $entry): AuditEntryId
    {
        $this->connection->execute(
            'INSERT INTO `mailadmin_audit` ('
            . '`recorded_at`, `actor`, `actor_role`, `client_ip`, `request_id`, `action`, '
            . '`object_type`, `object_id`, `domain_name`, `outcome`, `reason`, '
            . '`state_before`, `state_after`, `parent_id`'
            . ') VALUES ('
            . ':recorded_at, :actor, :actor_role, :client_ip, :request_id, :action, '
            . ':object_type, :object_id, :domain_name, :outcome, :reason, '
            . ':state_before, :state_after, :parent_id'
            . ')',
            [
                'recorded_at' => $this->clock->now()
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
                'actor' => $entry->actor->username,
                'actor_role' => $entry->actor->roleValue(),
                'client_ip' => $this->context->clientIp(),
                'request_id' => $this->context->requestId(),
                'action' => $entry->action->value,
                'object_type' => $entry->objectType,
                'object_id' => $entry->objectId,
                'domain_name' => $entry->domain,
                'outcome' => $entry->outcome->value,
                'reason' => $entry->reason,
                'state_before' => self::encode($this->redactor->apply($entry->before)),
                'state_after' => self::encode($this->redactor->apply($entry->after)),
                'parent_id' => $entry->parent?->value,
            ],
        );

        return AuditEntryId::of((int) $this->connection->lastInsertId());
    }

    /**
     * `null` stays `null`: the column distinguishes "there was no such state"
     * from "the state was empty", and `json_encode(null)` would write the string
     * `"null"` into it (U-61).
     *
     * `JSON_THROW_ON_ERROR` is deliberate. Silently storing `false` would leave
     * an entry that says a change happened and cannot say what changed, and the
     * failure it would hide — invalid UTF-8 out of a `latin1` column — is one
     * this schema can actually produce.
     *
     * @param array<string, mixed>|null $state
     */
    private static function encode(?array $state): ?string
    {
        if ($state === null) {
            return null;
        }

        return json_encode(
            $state,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}

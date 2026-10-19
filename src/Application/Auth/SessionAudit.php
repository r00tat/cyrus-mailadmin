<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

use Mailadmin\Application\Audit\AuditActor;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The audit entries the session endpoints write (S4).
 *
 * S4 draws the line for reads and attempts in one sentence: reads are not
 * recorded, **sign-ins and sign-in attempts are**. That is what this class
 * writes, and it exists rather than three inline calls for two reasons.
 *
 * **It is the one place the session vocabulary is spelled.** `session.sign_in`,
 * `session.sign_out`, `session.refresh_reused` — three names that have to stay
 * stable, because a search over the trail is only as good as the consistency of
 * what it searches for.
 *
 * **It never throws.** Everywhere else a failing audit write takes the operation
 * down with it (U-63), and that is the right trade: a change nobody can prove
 * happened should not stand. Here it is the wrong one. These calls sit on the
 * only path through which an administrator reaches the application at all, and
 * an audit table that cannot be written to would otherwise lock everyone out of
 * the installation — including the person who has come to fix it. The loss is a
 * row; the alternative is the building.
 *
 * ## What is not recorded, and why
 *
 * **A successful refresh.** A refresh continues a session that was recorded when
 * it began; it is not a new fact about who did what, and recording one per
 * administrator per hour would bury the entries that are. What *is* recorded is
 * a **reused** refresh token: that means a copy of a credential exists, which is
 * the single most serious thing this application can notice about itself.
 *
 * **An ordinary expiry.** A refresh token that ran out is a clock, not an event.
 */
final readonly class SessionAudit
{
    public function __construct(
        private AuditWriter $audit,
        private LoggerInterface $logger,
    ) {
    }

    public function signedIn(ActorContext $actor): void
    {
        $this->record(AuditEntry::creation(
            $actor,
            'session.sign_in',
            'session',
            $actor->username,
            after: [
                'role' => $actor->role->value,
                'domains' => $actor->isSuperuser() ? [] : $actor->scope->domainNames(),
            ],
        ));
    }

    /**
     * A refused attempt. The name is the one that was **sent** — it may be an
     * account that does not exist, and a burst of rows naming accounts that do
     * not exist is exactly the shape a password spray has.
     *
     * The reason is the internal one ("wrong password", "rate limited"), which
     * the response never carries: the endpoint answers all of them identically
     * on purpose (architecture 5.5), and the trail is where the difference is
     * allowed to be visible.
     */
    public function signInRefused(string $username, string $reason): void
    {
        $this->record(AuditEntry::refusal(
            AuditActor::named($username),
            'session.sign_in',
            'session',
            $username,
            reason: $reason,
        ));
    }

    public function signedOut(string $username, AdminRole $role, int $tokensRevoked): void
    {
        $this->record(AuditEntry::removal(
            AuditActor::withRole($username, $role),
            'session.sign_out',
            'session',
            $username,
            // Not `refresh_tokens_revoked`: the redactor keys on `token` and would
            // mask a count. A number of ended sessions is not a secret.
            before: ['sessions_ended' => $tokensRevoked],
        ));
    }

    /**
     * A spent refresh token turned up again: the family has been revoked and
     * both the thief and the rightful owner are signed out.
     *
     * Recorded as a failure rather than a refusal. Nobody was denied a
     * permission they lacked — a credential that was valid once was presented
     * twice, and the distinction matters when reading the trail: `denied` rows
     * are somebody reaching too far, this row is somebody holding a copy.
     */
    public function refreshReused(string $username, int $tokensRevoked): void
    {
        $this->record(AuditEntry::failure(
            AuditActor::named($username),
            'session.refresh_reused',
            'session',
            $username,
            reason: 'a spent refresh token was presented again; the family was revoked',
            // Not `refresh_tokens_revoked`: the redactor keys on `token` and would
            // mask a count. A number of ended sessions is not a secret.
            before: ['sessions_ended' => $tokensRevoked],
        ));
    }

    private function record(AuditEntry $entry): void
    {
        try {
            $this->audit->record($entry);
        } catch (Throwable $error) {
            $this->logger->error('Could not write a session entry to the audit trail.', [
                'admin' => $entry->actor->username,
                'action' => $entry->action->value,
                'reason' => $error->getMessage(),
            ]);
        }
    }
}

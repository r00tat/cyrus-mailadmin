<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Auth;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditEntryId;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Auth\SessionAudit;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the session endpoints leave behind, and what happens when they cannot.
 *
 * The last test is the one worth having. Everywhere else in the application a
 * failing audit write takes the operation down with it, deliberately (U-63);
 * here it must not, because these three calls sit on the only path by which an
 * administrator reaches the application. An audit table that has run out of disk
 * should cost a row, not the ability to sign in and fix it.
 */
#[CoversClass(SessionAudit::class)]
final class SessionAuditTest extends TestCase
{
    public function testASignInIsRecordedWithTheRoleAndScopeItWasGranted(): void
    {
        $trail = new InMemoryAuditTrail();

        $this->audit($trail)->signedIn(
            ActorContext::domainMaster('master', DomainName::fromString('own.example')),
        );

        $entry = $trail->last();

        self::assertNotNull($entry);
        self::assertSame('session.sign_in', $entry->action);
        self::assertSame('master', $entry->actor);
        self::assertSame(AdminRole::DomainMaster, $entry->actorRole);
        self::assertSame(AuditOutcome::Succeeded, $entry->outcome);
        self::assertSame(['role' => 'domainmaster', 'domains' => ['own.example']], $entry->after);
    }

    /**
     * The response tells nobody which of the four reasons it was (architecture
     * 5.5). The trail is where the difference is allowed to be visible.
     */
    public function testARefusedAttemptNamesTheNameThatWasSentAndTheInternalReason(): void
    {
        $trail = new InMemoryAuditTrail();

        $this->audit($trail)->signInRefused('does-not-exist', 'unknown administrator');

        $entry = $trail->last();

        self::assertNotNull($entry);
        self::assertSame('does-not-exist', $entry->actor);
        self::assertNull($entry->actorRole, 'Nobody was authenticated, so no role may be claimed.');
        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertSame('unknown administrator', $entry->reason);
    }

    public function testAReusedRefreshTokenIsRecordedAsAFailureRatherThanARefusal(): void
    {
        $trail = new InMemoryAuditTrail();

        $this->audit($trail)->refreshReused('anna', 3);

        $entry = $trail->last();

        self::assertNotNull($entry);
        self::assertSame('session.refresh_reused', $entry->action);
        self::assertSame(AuditOutcome::Failed, $entry->outcome);
        self::assertSame(['sessions_ended' => 3], $entry->before);
    }

    /**
     * The count of ended sessions has to survive redaction. It would not if it
     * were called `refresh_tokens_revoked`, because the redactor keys on
     * `token` — correctly, and this is the shape that avoids the collision.
     */
    public function testTheCountOfEndedSessionsIsNotMistakenForASecret(): void
    {
        $trail = new InMemoryAuditTrail();

        $this->audit($trail)->signedOut('anna', AdminRole::Superuser, 2);

        self::assertSame(['sessions_ended' => 2], $trail->last()?->before);
    }

    public function testAFailingWriteDoesNotBreakTheSignIn(): void
    {
        $audit = new SessionAudit(new class () implements AuditWriter {
            public function record(AuditEntry $entry): AuditEntryId
            {
                throw PersistenceError::queryFailed('INSERT INTO mailadmin_audit', new \PDOException('disk full'));
            }
        }, new NullLogger());

        $audit->signedIn(ActorContext::superuser('root'));
        $audit->signInRefused('anna', 'wrong password');
        $audit->signedOut('anna', AdminRole::Superuser, 1);
        $audit->refreshReused('anna', 1);

        // Nothing thrown. The failure is in the log, which is where an operator
        // will find out that the trail has stopped recording.
        $this->addToAssertionCount(1);
    }

    private function audit(InMemoryAuditTrail $trail): SessionAudit
    {
        return new SessionAudit($trail, new NullLogger());
    }
}

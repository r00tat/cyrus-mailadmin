<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Auth;

use Mailadmin\Application\Auth\AuthenticationFailed;
use Mailadmin\Application\Auth\AuthErrorCode;
use Mailadmin\Application\Auth\IssuedSession;
use Mailadmin\Application\Auth\RefreshSession;
use Mailadmin\Application\Auth\SessionAudit;
use Mailadmin\Application\Auth\SessionFactory;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Security\RefreshToken;
use Mailadmin\Infrastructure\Security\RefreshTokenRecord;
use Mailadmin\Infrastructure\Security\SigningKey;
use Mailadmin\Infrastructure\Security\SigningKeys;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Infrastructure\Security\TokenVerifier;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Rotation and reuse detection (architecture 5.4).
 *
 * This is the part of S2 where a mistake is invisible until it matters. A
 * refresh token that quietly works twice is not a failing test — it is a stolen
 * session that nobody notices, which is exactly the failure rotation exists to
 * make noisy.
 */
#[CoversClass(RefreshSession::class)]
#[CoversClass(SessionFactory::class)]
#[CoversClass(InMemoryRefreshTokenStore::class)]
#[CoversClass(RefreshToken::class)]
#[CoversClass(RefreshTokenRecord::class)]
final class RefreshSessionTest extends TestCase
{
    private const string SEED = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const int REFRESH_TTL = 43200;

    private FrozenClock $clock;
    private InMemoryAdminDirectory $directory;
    private InMemoryRefreshTokenStore $store;
    private InMemoryAuditTrail $audit;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->directory = new InMemoryAdminDirectory();
        $this->directory->given('anna', 'irrelevant-here', AdminRole::Superuser);
        $this->store = new InMemoryRefreshTokenStore();
        $this->audit = new InMemoryAuditTrail(clock: $this->clock);
    }

    public function testAValidTokenIsExchangedForANewPair(): void
    {
        $first = $this->beginSession();

        $second = $this->refresh()->handle($first->refreshToken->value);

        self::assertNotSame($first->refreshToken->value, $second->refreshToken->value);
        self::assertSame('anna', $this->verifier()->verify($second->accessToken->value)->subject);
    }

    public function testTheOldTokenStopsWorkingImmediately(): void
    {
        $first = $this->beginSession();

        $this->refresh()->handle($first->refreshToken->value);

        $failure = $this->failureFrom($first->refreshToken->value);

        self::assertSame(AuthErrorCode::REFRESH_REUSED, $failure->errorCode);
    }

    /**
     * The alarm. A spent token coming back means a copy exists, so the whole
     * family goes — deliberately logging out the rightful owner as well, because
     * that is the only way to be sure the copy is worthless.
     */
    public function testAReusedTokenRevokesTheWholeFamily(): void
    {
        $first = $this->beginSession();
        $second = $this->refresh()->handle($first->refreshToken->value);

        $this->failureFrom($first->refreshToken->value);

        // The successor, which the rightful owner is holding, is dead too.
        $failure = $this->failureFrom($second->refreshToken->value);

        self::assertSame(AuthErrorCode::REFRESH_INVALID, $failure->errorCode);

        foreach ($this->store->all() as $record) {
            self::assertTrue($record->isRevoked(), 'Every token of the family must be revoked.');
        }
    }

    /**
     * A distinct code, because the client's reaction differs: not "that expired"
     * but "sign in again, and somebody should look".
     */
    public function testAReuseIsReportedDifferentlyFromAnExpiry(): void
    {
        $first = $this->beginSession();
        $this->refresh()->handle($first->refreshToken->value);

        self::assertSame(
            AuthErrorCode::REFRESH_REUSED,
            $this->failureFrom($first->refreshToken->value)->errorCode,
        );
        self::assertSame(
            AuthErrorCode::REFRESH_INVALID,
            $this->failureFrom('a-token-that-was-never-issued')->errorCode,
        );
    }

    /**
     * Twelve hours from signing in, not from the last request. If a rotation
     * moved the deadline, a session could be kept alive forever by refreshing —
     * and the twelve-hour bound is what limits what a stolen refresh token is
     * worth.
     */
    public function testRotationDoesNotExtendTheFamilyDeadline(): void
    {
        $first = $this->beginSession();

        $this->clock->advance(3600);
        $second = $this->refresh()->handle($first->refreshToken->value);

        $this->clock->advance(3600);
        $third = $this->refresh()->handle($second->refreshToken->value);

        self::assertSame(
            $first->refreshExpiresAt->getTimestamp(),
            $third->refreshExpiresAt->getTimestamp(),
        );
    }

    public function testTheFamilyDiesAtItsDeadline(): void
    {
        $first = $this->beginSession();

        $this->clock->advance(self::REFRESH_TTL + 1);

        self::assertSame(
            AuthErrorCode::REFRESH_INVALID,
            $this->failureFrom($first->refreshToken->value)->errorCode,
        );
    }

    /**
     * An expired token must not raise the reuse alarm — it was never used twice,
     * and an alarm that fires on the ordinary case is an alarm people learn to
     * ignore.
     */
    public function testAnExpiredTokenIsNotMistakenForAReuse(): void
    {
        $first = $this->beginSession();

        $this->clock->advance(self::REFRESH_TTL + 1);
        $this->failureFrom($first->refreshToken->value);

        foreach ($this->store->all() as $record) {
            self::assertFalse($record->isRevoked(), 'An expiry must not revoke the family.');
        }
    }

    public function testNoTokenAtAllIsRefused(): void
    {
        self::assertSame(AuthErrorCode::REFRESH_INVALID, $this->failureFrom(null)->errorCode);
        self::assertSame(AuthErrorCode::REFRESH_INVALID, $this->failureFrom('   ')->errorCode);
    }

    /**
     * A refresh mints a new hour of access, so somebody removed in the meantime
     * must not get one — otherwise a session started before the change would
     * outlive it indefinitely, one refresh at a time.
     */
    public function testARemovedAdministratorCannotRefresh(): void
    {
        $first = $this->beginSession();

        $this->directory->forget('anna');

        self::assertSame(
            AuthErrorCode::REFRESH_INVALID,
            $this->failureFrom($first->refreshToken->value)->errorCode,
        );
    }

    public function testARemovedAdministratorsFamilyIsRevokedRatherThanLeftLying(): void
    {
        $first = $this->beginSession();
        $this->directory->forget('anna');

        $this->failureFrom($first->refreshToken->value);

        foreach ($this->store->all() as $record) {
            self::assertTrue($record->isRevoked());
        }
    }

    /**
     * The role is re-read on every refresh, so a demotion takes effect within
     * the hour rather than at the end of the twelve-hour family.
     */
    public function testTheNewAccessTokenCarriesTheCurrentRole(): void
    {
        $first = $this->beginSession();

        $this->directory->given('anna', 'irrelevant-here', AdminRole::DomainMaster, ['example.test']);

        $second = $this->refresh()->handle($first->refreshToken->value);

        self::assertSame(
            AdminRole::DomainMaster,
            $this->verifier()->verify($second->accessToken->value)->role,
        );
    }

    public function testTheSuccessorStaysInTheSameFamily(): void
    {
        $first = $this->beginSession();
        $this->refresh()->handle($first->refreshToken->value);

        $families = array_unique(array_map(
            static fn (RefreshTokenRecord $record): string => $record->family,
            $this->store->all(),
        ));

        self::assertCount(1, $families);
    }

    // -----------------------------------------------------------------

    private function beginSession(): IssuedSession
    {
        return $this->sessions()->begin(
            $this->issuer()->issue('anna', AdminRole::Superuser),
            'anna',
        );
    }

    private function refresh(): RefreshSession
    {
        return new RefreshSession(
            store: $this->store,
            directory: $this->directory,
            issuer: $this->issuer(),
            sessions: $this->sessions(),
            clock: $this->clock,
            audit: new SessionAudit($this->audit, new NullLogger()),
            logger: new NullLogger(),
        );
    }

    private function sessions(): SessionFactory
    {
        return new SessionFactory($this->store, $this->clock, self::REFRESH_TTL);
    }

    private function issuer(): TokenIssuer
    {
        return new TokenIssuer(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
            3600,
        );
    }

    private function verifier(): TokenVerifier
    {
        return new TokenVerifier(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
        );
    }

    private function failureFrom(?string $token): AuthenticationFailed
    {
        try {
            $this->refresh()->handle($token);
        } catch (AuthenticationFailed $failure) {
            return $failure;
        }

        self::fail('The refresh was expected to fail and did not.');
    }
}

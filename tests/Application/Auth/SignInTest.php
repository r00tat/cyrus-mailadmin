<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Auth;

use Mailadmin\Application\Auth\AuthenticationFailed;
use Mailadmin\Application\Auth\AuthErrorCode;
use Mailadmin\Application\Auth\SessionAudit;
use Mailadmin\Application\Auth\SessionFactory;
use Mailadmin\Application\Auth\SignIn;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Infrastructure\Security\RateLimiter;
use Mailadmin\Infrastructure\Security\SigningKey;
use Mailadmin\Infrastructure\Security\SigningKeys;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Infrastructure\Security\TokenVerifier;
use Mailadmin\Tests\Support\FrozenClock;
use Mailadmin\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `POST /auth/token`, without a database (architecture 5.4).
 *
 * The cases here are the ones where being wrong is a security hole rather than
 * a bug: the two failure modes answering identically, the legacy hash being
 * lifted exactly once, and an administrator who can no longer act being turned
 * away as though their password were wrong.
 */
#[CoversClass(SignIn::class)]
#[CoversClass(SessionFactory::class)]
#[CoversClass(AuthenticationFailed::class)]
final class SignInTest extends TestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';
    private const string SEED = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const string IP = '203.0.113.7';

    private FrozenClock $clock;
    private InMemoryAdminDirectory $directory;
    private InMemoryRefreshTokenStore $store;
    private InMemoryLoginAttemptLog $attempts;
    private PasswordHasher $hasher;
    private InMemoryAuditTrail $audit;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->directory = new InMemoryAdminDirectory();
        $this->store = new InMemoryRefreshTokenStore();
        $this->attempts = new InMemoryLoginAttemptLog();
        $this->audit = new InMemoryAuditTrail(clock: $this->clock);
        // Deliberately at the cheap end: the tests here are about behaviour,
        // not about the cost factor, and PasswordHasherTest already pins the
        // real parameters down. A default-cost Argon2id in a data provider with
        // four rows makes a suite people stop running.
        $this->hasher = new PasswordHasher(PasswordHashingOptions::of(
            bcryptCost: 4,
            argon2MemoryKib: 8192,
            argon2TimeCost: 1,
            argon2Threads: 1,
        ));
    }

    public function testTheRightPasswordYieldsATokenPair(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        $session = $this->signIn()->handle('anna', self::PASSWORD, self::IP);

        self::assertSame('anna', $this->verifier()->verify($session->accessToken->value)->subject);
        self::assertNotSame('', $session->refreshToken->value);
    }

    /**
     * The refresh token exists in the response and as a digest in the store —
     * never in cleartext anywhere else. A store that kept the plaintext would
     * turn a database read into a set of usable sessions.
     */
    public function testOnlyTheDigestOfTheRefreshTokenIsStored(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        $session = $this->signIn()->handle('anna', self::PASSWORD, self::IP);
        $stored = $this->store->all();

        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', $session->refreshToken->value), $stored[0]->tokenHash);
        self::assertNotSame($session->refreshToken->value, $stored[0]->tokenHash);
    }

    public function testTheRoleFromTheTableIsTheRoleInTheToken(): void
    {
        $this->givenAdmin('bruno', self::PASSWORD, AdminRole::DomainMaster, ['example.test']);

        $session = $this->signIn()->handle('bruno', self::PASSWORD, self::IP);

        self::assertSame(
            AdminRole::DomainMaster,
            $this->verifier()->verify($session->accessToken->value)->role,
        );
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        $failure = $this->failureFrom('anna', 'not-the-password');

        self::assertSame(AuthErrorCode::INVALID_CREDENTIALS, $failure->errorCode);
        self::assertSame(401, $failure->status);
    }

    /**
     * The whole point of architecture 5.5: an outsider must not be able to tell
     * an existing account from a made-up one. Same code, same status, same
     * wording.
     */
    public function testAnUnknownNameAndAWrongPasswordAreIndistinguishable(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        $forWrongPassword = $this->failureFrom('anna', 'wrong');
        $forUnknownName = $this->failureFrom('nobody', 'wrong');

        self::assertSame($forWrongPassword->errorCode, $forUnknownName->errorCode);
        self::assertSame($forWrongPassword->status, $forUnknownName->status);
        self::assertSame($forWrongPassword->getMessage(), $forUnknownName->getMessage());
    }

    /**
     * The wording is one thing; the clock is another. An unknown name has to
     * cost a verification too, or the endpoint answers by stopwatch what it
     * refuses to answer in words.
     *
     * Asserted as "both are far above zero and within a factor of each other"
     * rather than as a tight bound — a timing assertion on a shared build
     * machine has to be loose enough not to be flaky, and a missing dummy
     * verification is a difference of orders of magnitude, not of percent.
     */
    public function testAnUnknownNameStillCostsAVerification(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        $forExisting = $this->timeOf(fn () => $this->failureFrom('anna', 'wrong'));
        $forUnknown = $this->timeOf(fn () => $this->failureFrom('nobody', 'wrong'));

        self::assertGreaterThan(0.0, $forUnknown);
        self::assertGreaterThan(
            $forExisting / 20,
            $forUnknown,
            'An unknown user name was answered far faster than a wrong password. '
            . 'The dummy verification is not running.',
        );
    }

    /**
     * E-13, and the only moment it can happen: the plaintext is known exactly
     * once, when it has just been proven correct.
     *
     * @param non-empty-string $legacyHash
     */
    #[DataProvider('legacyHashes')]
    public function testALegacyHashIsLiftedOnASuccessfulSignIn(string $legacyHash, string $password): void
    {
        $this->directory->given('anna', $legacyHash);

        $this->signIn()->handle('anna', $password, self::IP);

        self::assertSame(['anna'], $this->directory->rehashed());
        self::assertStringStartsWith('$argon2id$', (string) $this->directory->storedHash('anna'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function legacyHashes(): array
    {
        return [
            'md5crypt' => [(string) crypt('secret', '$1$abcdefgh$'), 'secret'],
            'sha512crypt' => [(string) crypt('secret', '$6$abcdefgh$'), 'secret'],
            'SHA1 hex' => [sha1('secret'), 'secret'],
            'plaintext' => ['secret', 'secret'],
        ];
    }

    /**
     * `password_needs_rehash()` and not "rewrite every time": rehashing a hash
     * that is already current would write to `adminuser` on every sign-in for
     * nothing.
     */
    public function testACurrentHashIsNotRewritten(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        $this->signIn()->handle('anna', self::PASSWORD, self::IP);

        self::assertSame([], $this->directory->rehashed());
    }

    public function testAWrongPasswordDoesNotRewriteALegacyHash(): void
    {
        $this->directory->given('anna', sha1('secret'));

        $this->failureFrom('anna', 'not-secret');

        self::assertSame([], $this->directory->rehashed());
        self::assertSame(sha1('secret'), $this->directory->storedHash('anna'));
    }

    /**
     * A domain master whose last assignment was withdrawn has a correct password
     * and nothing to do. Letting them in would produce a session that answers
     * `404` everywhere; saying *why* would confirm the account exists.
     */
    public function testADomainMasterWithoutDomainsIsTurnedAwayLikeAnyoneElse(): void
    {
        $this->directory->given('bruno', $this->hashOf(self::PASSWORD), AdminRole::DomainMaster, []);

        $failure = $this->failureFrom('bruno', self::PASSWORD);

        self::assertSame(AuthErrorCode::INVALID_CREDENTIALS, $failure->errorCode);
    }

    public function testTheRateLimiterRefusesBeforeAnyPasswordIsChecked(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        for ($i = 0; $i < RateLimiter::USERNAME_LOCK_AT; ++$i) {
            $this->failureFrom('anna', 'wrong');
        }

        // Even the correct password is refused now, and with the other code.
        $failure = $this->failureFrom('anna', self::PASSWORD);

        self::assertSame(AuthErrorCode::RATE_LIMITED, $failure->errorCode);
        self::assertSame(429, $failure->status);
        self::assertGreaterThan(0, $failure->retryAfterSeconds);
    }

    public function testASuccessfulSignInClearsTheFailureCounter(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        $this->failureFrom('anna', 'wrong');
        $this->failureFrom('anna', 'wrong');
        $this->signIn()->handle('anna', self::PASSWORD, self::IP);

        self::assertSame(0, $this->attempts->countForUsername('anna', $this->clock->now()->setTimestamp(0)));
    }

    /**
     * Twelve hours from signing in (architecture 5.2). The deadline is the one a
     * rotation later copies rather than extends, so it starts here.
     */
    public function testTheRefreshFamilyGetsAnAbsoluteDeadline(): void
    {
        $this->givenAdmin('anna', self::PASSWORD);

        $session = $this->signIn()->handle('anna', self::PASSWORD, self::IP);

        self::assertSame(
            $this->clock->now()->getTimestamp() + 43200,
            $session->refreshExpiresAt->getTimestamp(),
        );
    }

    // -----------------------------------------------------------------

    private function signIn(): SignIn
    {
        return new SignIn(
            directory: $this->directory,
            hasher: $this->hasher,
            rateLimiter: new RateLimiter($this->attempts, $this->clock, new RecordingSleeper()),
            issuer: $this->issuer(),
            sessions: new SessionFactory($this->store, $this->clock, 43200),
            audit: new SessionAudit($this->audit, new NullLogger()),
            logger: new NullLogger(),
        );
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

    /**
     * @param list<string> $domains
     */
    private function givenAdmin(
        string $username,
        string $password,
        AdminRole $role = AdminRole::Superuser,
        array $domains = [],
    ): void {
        $this->directory->given($username, $this->hashOf($password), $role, $domains);
    }

    private function hashOf(string $password): string
    {
        return $this->hasher->hash(Password::alreadyInUse($password, PasswordSubject::AdminUser));
    }

    private function failureFrom(string $username, string $password): AuthenticationFailed
    {
        try {
            $this->signIn()->handle($username, $password, self::IP);
        } catch (AuthenticationFailed $failure) {
            return $failure;
        }

        self::fail(sprintf('Signing in as "%s" was expected to fail and did not.', $username));
    }

    /**
     * @param callable(): mixed $work
     */
    private function timeOf(callable $work): float
    {
        $started = hrtime(true);
        $work();

        return (hrtime(true) - $started) / 1e9;
    }
}

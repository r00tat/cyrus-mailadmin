<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Auth;

use Mailadmin\Application\Auth\SessionAudit;
use Mailadmin\Application\Auth\SessionFactory;
use Mailadmin\Application\Auth\SignOut;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Security\SigningKey;
use Mailadmin\Infrastructure\Security\SigningKeys;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Infrastructure\Security\TokenVerifier;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Signing out has to end both halves of the session.
 *
 * Revoking only the refresh family would leave the access token working for up
 * to an hour, which is not what anyone means by signing out. Denylisting only
 * the access token would leave the refresh token able to mint a new one a second
 * later. Neither half alone is a sign-out, so both are tested as one.
 */
#[CoversClass(SignOut::class)]
#[CoversClass(InMemoryTokenDenylist::class)]
final class SignOutTest extends TestCase
{
    private const string SEED = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private FrozenClock $clock;
    private InMemoryTokenDenylist $denylist;
    private InMemoryRefreshTokenStore $store;
    private InMemoryAuditTrail $audit;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->denylist = new InMemoryTokenDenylist();
        $this->store = new InMemoryRefreshTokenStore();
        $this->audit = new InMemoryAuditTrail(clock: $this->clock);
    }

    public function testTheAccessTokenIsDeadAtOnce(): void
    {
        $claims = $this->claimsFor('anna');

        $this->signOut()->handle($claims, null);

        self::assertTrue($this->denylist->isDenied($claims));
    }

    public function testTheRefreshFamilyIsRevoked(): void
    {
        $claims = $this->claimsFor('anna');
        $session = $this->sessions()->begin($this->issuer()->issue('anna', AdminRole::Superuser), 'anna');

        $this->signOut()->handle($claims, $session->refreshToken->value);

        foreach ($this->store->all() as $record) {
            self::assertTrue($record->isRevoked());
        }
    }

    /**
     * A sign-out button ends *this* session. The same person on their phone
     * stays signed in, which is what the word means everywhere else. Ending
     * every session is a different operation with a different trigger.
     */
    public function testAnotherSessionOfTheSamePersonSurvives(): void
    {
        $onLaptop = $this->sessions()->begin($this->issuer()->issue('anna', AdminRole::Superuser), 'anna');
        $onPhone = $this->sessions()->begin($this->issuer()->issue('anna', AdminRole::Superuser), 'anna');

        $this->signOut()->handle($this->claimsFor('anna'), $onLaptop->refreshToken->value);

        $revoked = [];

        foreach ($this->store->all() as $record) {
            $revoked[$record->tokenHash] = $record->isRevoked();
        }

        self::assertTrue($revoked[hash('sha256', $onLaptop->refreshToken->value)]);
        self::assertFalse($revoked[hash('sha256', $onPhone->refreshToken->value)]);
    }

    /**
     * Otherwise any authenticated administrator could sign out any other by
     * pasting their refresh token. Small, and free to close.
     */
    public function testARefreshTokenBelongingToSomebodyElseIsIgnored(): void
    {
        $brunosSession = $this->sessions()->begin(
            $this->issuer()->issue('bruno', AdminRole::Superuser),
            'bruno',
        );

        $this->signOut()->handle($this->claimsFor('anna'), $brunosSession->refreshToken->value);

        foreach ($this->store->all() as $record) {
            self::assertFalse($record->isRevoked(), "Anna's logout revoked Bruno's family.");
        }
    }

    public function testSigningOutTwiceIsNotAnError(): void
    {
        $claims = $this->claimsFor('anna');
        $session = $this->sessions()->begin($this->issuer()->issue('anna', AdminRole::Superuser), 'anna');

        $this->signOut()->handle($claims, $session->refreshToken->value);
        $this->signOut()->handle($claims, $session->refreshToken->value);

        self::assertTrue($this->denylist->isDenied($claims));
    }

    public function testAnUnknownRefreshTokenIsIgnoredRatherThanFatal(): void
    {
        $claims = $this->claimsFor('anna');

        $this->signOut()->handle($claims, 'this-was-never-issued');

        self::assertTrue($this->denylist->isDenied($claims));
    }

    /**
     * A token minted *after* the sign-out is a new session and must work. If the
     * denylist caught it, signing out would lock the person out until their old
     * token would have expired.
     */
    public function testANewTokenIssuedAfterwardsIsNotDenied(): void
    {
        $this->signOut()->handle($this->claimsFor('anna'), null);

        $this->clock->advance(5);

        self::assertFalse($this->denylist->isDenied($this->claimsFor('anna')));
    }

    /**
     * The reason is diagnostics. It must not reach a client, and nothing here
     * branches on it — but a row without one is a row nobody can explain later.
     */
    public function testTheRowRecordsWhySoAnOperatorCanTellThemApart(): void
    {
        self::assertSame('logout', TokenDenylist::REASON_LOGOUT);
    }

    // -----------------------------------------------------------------

    private function signOut(): SignOut
    {
        return new SignOut(
            $this->denylist,
            $this->store,
            $this->clock,
            new SessionAudit($this->audit, new NullLogger()),
            new NullLogger(),
        );
    }

    private function sessions(): SessionFactory
    {
        return new SessionFactory($this->store, $this->clock, 43200);
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

    private function claimsFor(string $subject): TokenClaims
    {
        $token = $this->issuer()->issue($subject, AdminRole::Superuser);
        $verifier = new TokenVerifier(
            SigningKeys::of(SigningKey::fromBase64Seed(self::SEED)),
            $this->clock,
            'mailadmin',
            'mailadmin-api',
        );

        return $verifier->verify($token->value);
    }
}

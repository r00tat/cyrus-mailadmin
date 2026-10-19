<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two kinds of revocation, and the cutoff that makes the second one safe.
 *
 * A subject-wide row is what a role change and a deleted account need (S2's open
 * points 4 and 6). It is only usable because of the `issued_before` cutoff: a
 * token minted *after* the revocation has to keep working, or revoking somebody
 * would also lock them out of signing in again.
 */
#[CoversClass(InMemoryTokenDenylist::class)]
final class InMemoryTokenDenylistTest extends TestCase
{
    public function testAnUnknownTokenIsNotDenied(): void
    {
        self::assertFalse((new InMemoryTokenDenylist())->isDenied($this->claims('anna', 'jti-1')));
    }

    public function testASingleTokenCanBeRevoked(): void
    {
        $denylist = new InMemoryTokenDenylist();
        $revoked = $this->claims('anna', 'jti-1');

        $denylist->denyToken($revoked, TokenDenylist::REASON_LOGOUT);

        self::assertTrue($denylist->isDenied($revoked));
        self::assertFalse(
            $denylist->isDenied($this->claims('anna', 'jti-2')),
            'Revoking one token must not revoke the others.',
        );
    }

    public function testRevokingASubjectCatchesEveryTokenItHasIssued(): void
    {
        $denylist = new InMemoryTokenDenylist();
        $now = $this->at('2026-01-01 12:00:00');

        $denylist->denySubject('anna', $now, $this->at('2026-01-01 13:00:00'), TokenDenylist::REASON_ROLE_CHANGE);

        self::assertTrue($denylist->isDenied($this->claims('anna', 'jti-1', $this->at('2026-01-01 11:30:00'))));
        self::assertTrue($denylist->isDenied($this->claims('anna', 'jti-2', $now)));
    }

    /**
     * The property that makes a subject-wide row safe to leave lying around: it
     * must not catch the token the person gets when they sign in again.
     */
    public function testATokenIssuedAfterTheCutoffIsUnaffected(): void
    {
        $denylist = new InMemoryTokenDenylist();

        $denylist->denySubject(
            'anna',
            $this->at('2026-01-01 12:00:00'),
            $this->at('2026-01-01 13:00:00'),
            TokenDenylist::REASON_ADMIN_REMOVED,
        );

        self::assertFalse(
            $denylist->isDenied($this->claims('anna', 'jti-new', $this->at('2026-01-01 12:00:01'))),
        );
    }

    public function testRevokingOneSubjectLeavesTheOthersAlone(): void
    {
        $denylist = new InMemoryTokenDenylist();

        $denylist->denySubject(
            'anna',
            $this->at('2026-01-01 12:00:00'),
            $this->at('2026-01-01 13:00:00'),
            TokenDenylist::REASON_ROLE_CHANGE,
        );

        self::assertFalse($denylist->isDenied($this->claims('bruno', 'jti-1')));
    }

    /**
     * A second revocation may widen the row but never narrow it. Narrowing would
     * quietly un-revoke tokens the first call had caught.
     */
    public function testASecondRevocationCannotMoveTheCutoffBackwards(): void
    {
        $denylist = new InMemoryTokenDenylist();
        $expires = $this->at('2026-01-01 14:00:00');

        $denylist->denySubject('anna', $this->at('2026-01-01 12:00:00'), $expires, 'role_change');
        $denylist->denySubject('anna', $this->at('2026-01-01 10:00:00'), $expires, 'role_change');

        self::assertTrue(
            $denylist->isDenied($this->claims('anna', 'jti-1', $this->at('2026-01-01 11:00:00'))),
            'The earlier cutoff overwrote the later one, un-revoking a token.',
        );
    }

    public function testPruningRemovesOnlyWhatCanNoLongerMatter(): void
    {
        $denylist = new InMemoryTokenDenylist();

        $stillNeeded = $this->claims('anna', 'jti-live', $this->at('2026-01-01 12:00:00'), $this->at('2026-01-01 13:00:00'));
        $longGone = $this->claims('bruno', 'jti-dead', $this->at('2026-01-01 08:00:00'), $this->at('2026-01-01 09:00:00'));

        $denylist->denyToken($stillNeeded, TokenDenylist::REASON_LOGOUT);
        $denylist->denyToken($longGone, TokenDenylist::REASON_LOGOUT);

        self::assertSame(1, $denylist->prune($this->at('2026-01-01 10:00:00')));
        self::assertTrue($denylist->isDenied($stillNeeded));
        self::assertFalse($denylist->isDenied($longGone));
    }

    private function claims(
        string $subject,
        string $jti,
        ?DateTimeImmutable $issuedAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): TokenClaims {
        return new TokenClaims(
            subject: $subject,
            role: AdminRole::Superuser,
            jti: $jti,
            issuedAt: $issuedAt ?? $this->at('2026-01-01 12:00:00'),
            expiresAt: $expiresAt ?? $this->at('2026-01-01 13:00:00'),
        );
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

use Mailadmin\Infrastructure\Security\RefreshToken;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Runtime\Clock;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * `POST /auth/logout`: both halves of the session, or it is not a sign-out.
 *
 *  - The access token goes on the denylist until its own `exp`. Without this,
 *    "signed out" would mean "in up to an hour", which is not what the word
 *    means to the person clicking it.
 *  - The refresh family is revoked. Without this, the token in the cookie could
 *    mint a new access token immediately.
 *
 * ## Only the family that was presented
 *
 * Signing out revokes the family belonging to the refresh token that came with
 * the request, not every family of that administrator. A second browser, or the
 * same person on their phone, stays signed in — which is what a sign-out button
 * means everywhere else. Ending *all* sessions is a different operation with a
 * different trigger (a role change, a removed account), and it goes through
 * {@see TokenDenylist::denySubject()}.
 *
 * ## A presented token that belongs to somebody else is ignored
 *
 * The family is only revoked when the record's subject matches the token's. Any
 * authenticated administrator could otherwise sign out any other by pasting
 * their refresh token — a small thing, but it is free to close.
 *
 * Idempotent throughout: signing out twice writes the same denylist row and
 * revokes an already revoked family.
 */
final readonly class SignOut
{
    public function __construct(
        private TokenDenylist $denylist,
        private RefreshTokenStore $store,
        private Clock $clock,
        private SessionAudit $audit,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(TokenClaims $claims, #[SensitiveParameter] ?string $refreshToken): void
    {
        $this->denylist->denyToken($claims, TokenDenylist::REASON_LOGOUT);

        $revoked = $this->revokeFamilyOf($claims, $refreshToken);

        $this->logger->info('An administrator signed out.', [
            'admin' => $claims->subject,
            'refresh_tokens_revoked' => $revoked,
        ]);

        $this->audit->signedOut($claims->subject, $claims->role, $revoked);
    }

    private function revokeFamilyOf(TokenClaims $claims, #[SensitiveParameter] ?string $refreshToken): int
    {
        if ($refreshToken === null || trim($refreshToken) === '') {
            return 0;
        }

        $record = $this->store->find(RefreshToken::hashOf($refreshToken));

        if ($record === null || $record->subject !== $claims->subject) {
            return 0;
        }

        return $this->store->revokeFamily($record->family, $this->clock->now());
    }
}

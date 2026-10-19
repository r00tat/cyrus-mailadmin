<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Password\PersonalTerms;
use Mailadmin\Domain\Policy\AdminPolicy;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Runtime\Clock;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * `PUT /admins/me/password`.
 *
 * The one operation in A4 that a domain master may perform. webcyradm had this
 * right: `changeadminpasswd` was the single screen with no role check at all,
 * and a domain master who had to ask a superuser for a new password would be
 * handing their account to somebody who then knows it.
 *
 * ## The subject is the token, never the request
 *
 * The name comes from the verified {@see ActorContext}. There is no parameter
 * for it, which is why `profile.password.write` — a permission *everyone* holds
 * — cannot be pointed at somebody else's account. The policy is still asked,
 * with the actor's own name, so that the rule is stated where it is enforced
 * rather than implied by the absence of an argument. Setting another
 * administrator's password is `PATCH /admins/{username}` and needs
 * `admin.write`.
 *
 * ## The current password is required
 *
 * Being authenticated is not enough. An access token lives for an hour and sits
 * in `localStorage` (E-15); if that were sufficient, a stolen one would be
 * enough to take the account away from its owner permanently rather than for the
 * rest of the hour.
 *
 * The verification accepts every legacy format the stored hash may still be in
 * (architecture 5.5) — an administrator who has not signed in since before E-13
 * can still change their password.
 *
 * ## Every session ends
 *
 * All refresh families of this administrator are revoked, this client's
 * included. Sessions opened with the old password should not outlive it, and
 * sparing "the current one" would mean trusting the client's word about which
 * one that is. The access token in hand keeps working until it expires — S2's
 * denylist is reachable only from `POST /auth/logout`, which holds the token's
 * `jti`; the price is bounded by the 60-minute lifetime that is not negotiable
 * anyway.
 */
final readonly class ChangeOwnPassword
{
    public function __construct(
        private AdminStore $store,
        private PasswordPolicy $passwords,
        private PasswordHasher $hasher,
        private RefreshTokenStore $refreshTokens,
        private AuditWriter $audit,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Password\InvalidPassword
     * @throws \Mailadmin\Infrastructure\Security\PasswordHashingFailed
     * @throws AdminOperationFailed
     */
    public function handle(
        ActorContext $actor,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
    ): void {
        AdminPolicy::requireOwnPasswordChange($actor, $actor->username);

        $stored = $this->store->findPasswordHash($actor->username)
            ?? throw AdminOperationFailed::notFound($actor->username);

        if (!$this->hasher->verify($currentPassword, $stored)) {
            // `notice`, not `warning`: mistyping one's own password is ordinary.
            // What makes the line worth writing is a burst of them.
            $this->logger->notice('A password change was refused: the current password is wrong.', [
                'admin' => $actor->username,
            ]);

            throw AdminOperationFailed::wrongCurrentPassword($actor->username);
        }

        $hash = $this->hasher->hash(
            $this->passwords->accept(
                $newPassword,
                PasswordSubject::AdminUser,
                PersonalTerms::forAdmin($actor->username),
            ),
        );

        $this->store->atomically(function () use ($actor, $hash): void {
            $this->store->replacePasswordHash($actor->username, $hash);

            // Inside the transaction (U-63), and with no `before` and no
            // `after`: the only thing that changed is the hash, and the hash is
            // exactly what an audit trail must not carry. The entry says who
            // changed their own password and when, which is what an operator can
            // act on.
            $this->audit->record(AuditEntry::change($actor, 'admin.password.change', 'admin', $actor->username));
        });

        $revoked = $this->refreshTokens->revokeSubject($actor->username, $this->clock->now());

        $this->logger->info('An administrator changed their own password.', [
            'admin' => $actor->username,
            'refresh_tokens_revoked' => $revoked,
        ]);
    }
}

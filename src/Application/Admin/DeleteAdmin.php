<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AdminPolicy;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Runtime\Clock;
use Psr\Log\LoggerInterface;

/**
 * `DELETE /admins/{username}`.
 *
 * ## Two refusals, in this order
 *
 *  1. **Oneself** (`admin.self_deletion`). Checked first because it is the
 *     cheapest and because the answer does not depend on the table. A superuser
 *     deleting their own account ends the session they are working in, and there
 *     is always another superuser who can do it deliberately — the
 *     last-superuser rule guarantees there is.
 *  2. **The last superuser** (`admin.last_superuser`). Counted in the database
 *     at the moment of the delete; the count changes without this session
 *     hearing about it.
 *
 * ## What is deleted with the row
 *
 * `domainadmin`, the legacy `settings` preferences and this application's
 * `mailadmin_admin_setting` — all keyed by the same name, all in one
 * transaction. webcyradm deleted the `settings` row too, and A4 keeps that: the
 * alternative is that the next administrator who happens to be given the same
 * name inherits somebody else's preferences.
 *
 * ## The sessions
 *
 * The access token stops working immediately without anything being done here:
 * role and scope are read from the database on every request (architecture 5.3),
 * and a name with no row yields `401`. The refresh families are revoked anyway —
 * `RefreshSession` would also refuse them for the same reason, but leaving rows
 * behind that name an administrator who no longer exists is a way to be puzzled
 * later.
 *
 * Revocation happens **after** the delete. Either order leaves a window; this
 * one leaves the harmless side of it, because a revocation that fails after a
 * successful delete costs nothing (the tokens are already useless), whereas a
 * delete that fails after a successful revocation would have signed a still-valid
 * administrator out for no reason.
 */
final readonly class DeleteAdmin
{
    public function __construct(
        private AdminStore $store,
        private RefreshTokenStore $refreshTokens,
        private AuditWriter $audit,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $ifMatch the `If-Match` header, or `null` when none was
     *                             sent. Optional (architecture 7.1); a mismatch
     *                             is `412`.
     *
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws AdminOperationFailed
     */
    public function handle(ActorContext $actor, string $username, ?string $ifMatch = null): void
    {
        AdminPolicy::requireDelete($actor);

        $target = $this->store->find($username) ?? throw AdminOperationFailed::notFound($username);

        if (!AdminEtag::allows($ifMatch, $target)) {
            throw AdminOperationFailed::preconditionFailed($username);
        }

        if (AdminPolicy::isSelf($actor, $target->username)) {
            throw AdminOperationFailed::selfDeletion($target->username);
        }

        if ($target->isSuperuser() && $this->store->countSuperusers() <= 1) {
            throw AdminOperationFailed::lastSuperuser($target->username);
        }

        $before = $target->representation();

        $this->store->atomically(function () use ($actor, $username, $before): void {
            $this->store->delete($username);

            $this->audit->record(AuditEntry::removal($actor, 'admin.delete', 'admin', $username, before: $before));
        });

        $revoked = $this->refreshTokens->revokeSubject($username, $this->clock->now());

        $this->logger->info('An administrator account was deleted.', [
            'admin' => $actor->username,
            'deleted_admin' => $username,
            'refresh_tokens_revoked' => $revoked,
        ]);

    }
}

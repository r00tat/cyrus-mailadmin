<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Infrastructure\Security\RefreshToken;
use Mailadmin\Infrastructure\Security\RefreshTokenRecord;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Mailadmin\Runtime\Clock;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * `POST /auth/refresh`: rotation with reuse detection (architecture 5.4).
 *
 * ## The order is the whole mechanism
 *
 * The token is **claimed first** — one conditional `UPDATE` that both marks it
 * consumed and reports whether this call was the one that did it. Only then is
 * anything else asked. Reading the record first and updating afterwards would
 * leave a window in which two requests both see a usable token, and that window
 * is exactly what a thief racing the rightful owner needs.
 *
 * A failed claim is then explained by reading the record:
 *
 * | What is found | Answer |
 * |---|---|
 * | nothing | `auth.refresh_invalid` — never issued, or long pruned |
 * | revoked or expired | `auth.refresh_invalid` — sign in again |
 * | **already consumed** | the family is revoked, then `auth.refresh_reused` |
 *
 * The third row is the alarm. A refresh token is valid exactly once, so seeing a
 * spent one means a copy exists. Revoking the family logs out both the thief and
 * the owner — deliberately: the owner signing in again is a small cost, and it
 * is the only way to be sure the copy is worthless.
 *
 * ## The administrator is re-checked
 *
 * A refresh mints a *new* access token, which is a new hour of access. Someone
 * removed in the meantime, or a domain master whose last assignment was
 * withdrawn, must not get one — otherwise a session started before the change
 * would outlive it indefinitely, one refresh at a time.
 */
final readonly class RefreshSession
{
    public function __construct(
        private RefreshTokenStore $store,
        private AdminDirectory $directory,
        private TokenIssuer $issuer,
        private SessionFactory $sessions,
        private Clock $clock,
        private SessionAudit $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $presented the token from the cookie or the body
     *
     * @throws AuthenticationFailed
     */
    public function handle(#[SensitiveParameter] ?string $presented): IssuedSession
    {
        if ($presented === null || trim($presented) === '') {
            throw AuthenticationFailed::refreshInvalid();
        }

        $now = $this->clock->now();
        $hash = RefreshToken::hashOf($presented);

        if (!$this->store->consume($hash, $now)) {
            $this->explainFailedClaim($hash, $now);
        }

        $record = $this->store->find($hash);

        if ($record === null) {
            // Claimed a moment ago and gone now. Only a concurrent prune can do
            // this, and the safe reading is "not usable".
            throw AuthenticationFailed::refreshInvalid();
        }

        $actor = $this->directory->findActor($record->subject);

        if ($actor === null) {
            $this->store->revokeFamily($record->family, $now);

            $this->logger->warning('A refresh was refused: the administrator can no longer act.', [
                'admin' => $record->subject,
            ]);

            throw AuthenticationFailed::refreshInvalid();
        }

        return $this->sessions->continueFamily(
            $this->issuer->issue($actor->username, $actor->role),
            $record,
        );
    }

    /**
     * Turn a refused claim into the right failure — and raise the alarm when it
     * was a reuse.
     *
     * @throws AuthenticationFailed always
     *
     * @phpstan-return never
     */
    private function explainFailedClaim(string $hash, \DateTimeImmutable $now): never
    {
        $record = $this->store->find($hash);

        if ($record === null) {
            throw AuthenticationFailed::refreshInvalid();
        }

        if ($record->isConsumed() && !$record->isRevoked()) {
            $revoked = $this->store->revokeFamily($record->family, $now);

            // `critical`, not `warning`. Everything else on this path is an
            // ordinary expiry; this one means a refresh token exists in two
            // places, and somebody should look.
            $this->logger->critical('A spent refresh token was presented again; revoking the family.', [
                'admin' => $record->subject,
                'family' => $record->family,
                'tokens_revoked' => $revoked,
            ]);

            // The one refresh outcome that reaches the audit trail. A successful
            // rotation is the continuation of a session already recorded at
            // sign-in; this is a credential existing in two places.
            $this->audit->refreshReused($record->subject, $revoked);

            throw AuthenticationFailed::refreshReused();
        }

        $this->logRoutineRefusal($record, $now);

        throw AuthenticationFailed::refreshInvalid();
    }

    private function logRoutineRefusal(RefreshTokenRecord $record, \DateTimeImmutable $now): void
    {
        $this->logger->info('A refresh token was refused.', [
            'admin' => $record->subject,
            'reason' => match (true) {
                $record->isRevoked() => 'revoked',
                $record->isExpired($now) => 'expired',
                default => 'not usable',
            },
        ]);
    }
}

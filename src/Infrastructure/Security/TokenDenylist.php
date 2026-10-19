<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use DateTimeImmutable;

/**
 * The exception list a stateless token needs (architecture 5.4).
 *
 * Consulted on **every** authenticated request. That is one indexed read per
 * request, and it buys the difference between "signed out" meaning now and
 * meaning within the hour — for a tool that can delete mailboxes and set
 * passwords, that difference is worth a query.
 *
 * Two ways to revoke, and the second is the reason this is not a set of `jti`
 * strings:
 *
 *  - {@see denyToken()} — one token, by `jti`. What signing out does.
 *  - {@see denySubject()} — every token of one administrator issued at or
 *    before a cutoff. What a role change and a deleted account need. Without
 *    it the application would have to keep a `sub` -> open `jti` index purely
 *    to be able to revoke, and that index would have to be maintained on the
 *    hot path for something that happens twice a year.
 *
 * A token minted *after* the cutoff is unaffected, so a subject-wide row can be
 * left in place until it expires without locking the person out of their next
 * sign-in.
 */
interface TokenDenylist
{
    /** Why a row is there. Diagnostics only — never sent to a client. */
    public const string REASON_LOGOUT = 'logout';
    public const string REASON_ROLE_CHANGE = 'role_change';
    public const string REASON_ADMIN_REMOVED = 'admin_removed';

    /**
     * Revoke one access token until it would have expired anyway.
     *
     * Idempotent: signing out twice writes the same row twice and is not an
     * error.
     */
    public function denyToken(TokenClaims $claims, string $reason): void;

    /**
     * Revoke every token of `$subject` issued at or before `$issuedBefore`.
     *
     * `$expiresAt` is when the row stops mattering — the latest `exp` any
     * affected token can carry, which the caller knows from the access-token
     * lifetime.
     */
    public function denySubject(
        string $subject,
        DateTimeImmutable $issuedBefore,
        DateTimeImmutable $expiresAt,
        string $reason,
    ): void;

    /**
     * Is this token revoked?
     *
     * True when a row names its `jti`, or when a subject-wide row for its `sub`
     * has a cutoff at or after its `iat`.
     */
    public function isDenied(TokenClaims $claims): bool;

    /**
     * Delete rows whose `expires_at` has passed. Returns the number removed.
     */
    public function prune(DateTimeImmutable $before): int;
}

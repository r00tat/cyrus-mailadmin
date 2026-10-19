<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use DateTimeImmutable;

/**
 * Where refresh tokens live.
 *
 * An interface, so that the rotation logic — the part where a mistake means a
 * stolen token keeps working — is tested against a double instead of against a
 * database that has to be running. The SQL implementation is then tested once,
 * for its SQL.
 *
 * ## The one contract that matters
 *
 * {@see consume()} must be **atomic**: it marks a token consumed and reports
 * whether *this* call was the one that did it. Two requests arriving with the
 * same token — a browser that retried, or a thief racing the owner — must not
 * both be told yes. A read-then-write pair in the caller would allow exactly
 * that, which is why the check and the write are one method here rather than
 * `find()` plus `markConsumed()`.
 */
interface RefreshTokenStore
{
    public function store(RefreshTokenRecord $record): void;

    /**
     * The record for a token, whatever state it is in — `null` only when no
     * such token was ever issued.
     */
    public function find(string $tokenHash): ?RefreshTokenRecord;

    /**
     * Claim this token: mark it consumed if it is usable, and say whether this
     * call was the one that succeeded.
     *
     * `false` means somebody else got there first, or it was never usable. The
     * caller then asks {@see find()} what happened and decides between "expired,
     * sign in again" and "already consumed — this is a reuse, burn the family".
     */
    public function consume(string $tokenHash, DateTimeImmutable $at): bool;

    /**
     * Revoke every token of a family, consumed or not. Returns how many rows
     * changed, which is what a reuse alarm reports.
     */
    public function revokeFamily(string $family, DateTimeImmutable $at): int;

    /**
     * Revoke every family of one administrator — for a deleted account or a
     * role change, where sessions elsewhere have to end too.
     */
    public function revokeSubject(string $subject, DateTimeImmutable $at): int;

    /**
     * Delete rows whose `expires_at` is at or before `$before`. Returns the
     * number removed.
     *
     * Only expired rows: a consumed but unexpired token still has to be
     * recognisable, because recognising it is how a reuse is caught.
     */
    public function prune(DateTimeImmutable $before): int;
}

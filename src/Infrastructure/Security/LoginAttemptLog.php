<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use DateTimeImmutable;

/**
 * The counters behind the sign-in rate limiter (architecture 5.6).
 *
 * Only failures are recorded, and a success clears the user name's rows. The
 * table is therefore the length of the current attack rather than of the
 * installation's history, and someone who mistypes their password twice a week
 * never accumulates their way into a lockout.
 *
 * Counted per user name **and** per client address, because either alone has a
 * hole: by name only, a botnet spreads the attempts across addresses; by
 * address only, one person behind a NAT gateway locks out everyone with them.
 */
interface LoginAttemptLog
{
    public function recordFailure(string $username, string $clientIp, DateTimeImmutable $at): void;

    public function countForUsername(string $username, DateTimeImmutable $since): int;

    public function countForClient(string $clientIp, DateTimeImmutable $since): int;

    /**
     * Forget this user name's failures. Called on a successful sign-in.
     *
     * A row carries both keys, so removing a name's rows also removes that
     * name's contribution to its address's count — there is no third state in
     * which a failure is forgotten for one key and remembered for the other.
     * What is **not** cleared is everything the address accumulated under other
     * names, which is the part that matters: one correct password does not
     * vouch for the address that fifty wrong ones came from.
     */
    public function clearUsername(string $username): void;

    /**
     * Delete attempts older than `$before`. Returns the number removed.
     */
    public function prune(DateTimeImmutable $before): int;
}

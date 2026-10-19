<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Access;

use SensitiveParameter;

/**
 * Read access to `adminuser` and `domainadmin` — the two tables that answer
 * "who is this, and what may they see".
 *
 * A port rather than a repository call, for the same reason the mailstore has
 * one (U-37): S2's use cases and the authentication middleware are the part of
 * the system where a mistake is a security hole, and they should be testable
 * without a database. The SQL lives in
 * {@see \Mailadmin\Infrastructure\Persistence\SqlAdminDirectory}, the double in
 * {@see \Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory}.
 *
 * **The scope is read, never cached across requests.** Architecture 5.3 keeps
 * the domain assignment out of the token precisely so that withdrawing it takes
 * effect at once; a cache with a lifetime would put the gap back. Within one
 * request the answer is asked for once, which is where the "short-lived cache"
 * in the architecture ends up being.
 *
 * The write method is here and not in A4's repository because it belongs to the
 * sign-in path: an accepted legacy hash is rewritten as Argon2id at the moment
 * it is proven correct (E-13), and that is the only time the plaintext is known.
 */
interface AdminDirectory
{
    /**
     * The stored hash and role for `$username`, or `null` if there is no such
     * administrator.
     *
     * The caller must answer identically in both cases — see architecture 5.5
     * and {@see \Mailadmin\Infrastructure\Security\PasswordHasher::verifyDummy()}.
     */
    public function findCredentials(string $username): ?AdminCredentials;

    /**
     * Role and visible domains as they are **now**.
     *
     * `null` means "cannot act": no such row, or a domain master with no domain
     * assigned. The second case is a misconfiguration rather than an attack, and
     * an implementation should say so in the log — but it still yields `null`,
     * because a session that may see nothing is not a session worth handing out.
     */
    public function findActor(string $username): ?ActorContext;

    /**
     * Replace the stored hash, without touching anything else about the row.
     *
     * Called after a successful sign-in when the stored format is no longer the
     * target one. Silently does nothing if the administrator has meanwhile
     * disappeared: the sign-in still succeeded, and failing it now over a
     * bookkeeping write would be the wrong trade.
     */
    public function replacePasswordHash(string $username, #[SensitiveParameter] string $hash): void;
}

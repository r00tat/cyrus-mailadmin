<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Model\DomainName;

/**
 * A name that is about to become a domain must not inherit an earlier one (U-99).
 *
 * `domainadmin` and `virtual` are not tied to the `domain` row. Rows an earlier
 * domain of the same name left behind — through webcyradm, which runs in
 * parallel (E-18), or through a deletion before U-95 — would apply to the new
 * domain the moment it exists: an assignment hands it to its old domain master,
 * a catchall delivers its mail to an old mailbox. Nobody decided either.
 *
 * So creating a domain, or renaming one onto the name, is refused while such
 * rows exist. Nothing is cleaned up here: existing orphan assignments stay
 * visible in A4 (U-53), and `virtual` is A3's (U-95). A superuser removes them
 * deliberately, and then the name is free.
 *
 * Shared by {@see CreateDomain} and {@see RenameDomain}; both run it as a
 * courtesy check and again under the namespace lock (U-97). The lock does not
 * hold webcyradm back — that is the parallel operation E-18 accepts.
 */
final class DomainLeftovers
{
    private function __construct()
    {
    }

    /**
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public static function requireNone(DomainStore $store, DomainName $name): void
    {
        $assignments = $store->assignmentCount($name);
        $addresses = $store->addressCount($name);

        if ($assignments > 0 || $addresses > 0) {
            throw DomainOperationFailed::leftoverState($name, $assignments, $addresses);
        }
    }
}

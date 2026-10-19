<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Domain\Model\AdminRole;

/**
 * A role together with the domains that go with it, checked once.
 *
 * {@see CreateAdmin} and {@see UpdateAdmin} arrive at the same three questions
 * from different directions, and answering them twice is how the two would drift:
 *
 *  1. **Do role and scope agree?** A superuser sees every domain, so naming
 *     individual ones for them is a contradiction, not a refinement. A domain
 *     master with no domain cannot act at all — `AdminDirectory::findActor()`
 *     answers `null` and the sign-in refuses them — so an empty list would lock
 *     somebody out with nothing to see for it.
 *  2. **Is the wildcard being smuggled in?** `*` is how webcyradm writes "all
 *     domains" (see {@see AdminRole::ALL_DOMAINS}), and U-43 honours the row
 *     wherever it finds it. Accepting it as a domain *name* here would make
 *     "domain master for `*`" a way to become a superuser without the role.
 *     The contract's pattern rejects it as well; this is the second lock,
 *     because a use case may also be reached from a command.
 *  3. **Do the domains exist?** An assignment to a domain with no row in
 *     `domain` is the state U-22 says to *show* where it already exists — and
 *     the state this package refuses to *create*.
 *
 * The names are trimmed, de-duplicated and sorted, so that two requests naming
 * the same domains in a different order produce the same rows and the same
 * `ETag`.
 */
final readonly class AdminAssignment
{
    /**
     * @param list<string> $domains empty for a superuser
     */
    private function __construct(
        public AdminRole $role,
        public array $domains,
    ) {
    }

    /**
     * @param list<string> $domains as the request sent them
     *
     * @throws AdminOperationFailed when role and scope disagree, or a domain
     *                              does not exist
     */
    public static function validated(
        AdminStore $store,
        string $username,
        AdminRole $role,
        array $domains,
    ): self {
        $named = self::normalise($domains);

        if ($role->isSuperuser()) {
            if ($named !== []) {
                throw AdminOperationFailed::superuserWithDomains($username);
            }

            return new self($role, []);
        }

        if ($named === []) {
            throw AdminOperationFailed::domainMasterWithoutDomains($username);
        }

        $unknown = array_values(array_diff($named, $store->existingDomains($named)));

        if ($unknown !== []) {
            throw AdminOperationFailed::unknownDomains($unknown);
        }

        return new self($role, $named);
    }

    /**
     * @param list<string> $domains
     *
     * @return list<string>
     */
    private static function normalise(array $domains): array
    {
        $clean = [];

        foreach ($domains as $domain) {
            $name = trim($domain);

            // The wildcard is a scope, not a name. See the class comment.
            if ($name === '' || $name === AdminRole::ALL_DOMAINS) {
                continue;
            }

            if (!in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }

        sort($clean);

        return $clean;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AdminPolicy;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * `GET /admins`.
 *
 * The shortest use case in the package, and the one worth explaining: it does
 * **not** narrow the query to the actor's domains, and that is a decision rather
 * than an omission.
 *
 * `Repository::scopedTo()` exists so that no A-package has to remember an `if`
 * around a domain restriction (U-45). It needs a domain column to restrict on.
 * `adminuser` has none — an administrator is not in a domain, and `domainadmin`
 * is a list of what they may see, not a statement of where they live. What
 * bounds this listing instead is the permission: `admin.read` is superuser-only
 * (U-43), so the only role that reaches this method is the one whose scope is
 * unrestricted anyway.
 *
 * The policy is still called, and called first. A listing that checked nothing
 * because "the middleware already did" would be a listing that stops checking
 * the day somebody reuses it from a command.
 */
final readonly class ListAdmins
{
    public function __construct(private AdminStore $store)
    {
    }

    /**
     * @return Page<AdminAccount>
     *
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     */
    public function handle(ActorContext $actor, ListCriteria $criteria): Page
    {
        AdminPolicy::requireRead($actor);

        return $this->store->list($criteria);
    }
}

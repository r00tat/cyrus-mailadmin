<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AdminPolicy;

/**
 * `GET /admins/{username}`.
 *
 * Permission first, lookup second — the order S3 fixed (U-44). A role that may
 * not read administrators at all learns nothing from the `403`, whereas looking
 * up first and refusing afterwards would answer differently for a name that
 * exists and one that does not.
 */
final readonly class GetAdmin
{
    public function __construct(private AdminStore $store)
    {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws AdminOperationFailed when there is no such administrator
     */
    public function handle(ActorContext $actor, string $username): AdminAccount
    {
        AdminPolicy::requireRead($actor);

        return $this->store->find($username) ?? throw AdminOperationFailed::notFound($username);
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AdminPolicy;

/**
 * `GET /admins/me/preferences`.
 *
 * The subject is the verified token, never the request — there is no parameter
 * through which one administrator could read another's preferences.
 */
final readonly class GetOwnPreferences
{
    public function __construct(private AdminPreferenceStore $store)
    {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     */
    public function handle(ActorContext $actor): AdminPreferences
    {
        AdminPolicy::requireOwnPreferencesRead($actor);

        return $this->store->find($actor->username);
    }
}

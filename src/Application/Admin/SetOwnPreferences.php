<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AdminPolicy;
use Mailadmin\Runtime\Clock;

/**
 * `PUT /admins/me/preferences`.
 *
 * Like the own-password change, the subject is the verified token and nothing
 * else (U-90). Unlike it, nothing here needs a second factor: the worst a stolen
 * token can do with this operation is switch the owner's interface language.
 *
 * ## Audited, and only when something changed
 *
 * Every state-changing operation writes to `mailadmin_audit` (CLAUDE.md), inside
 * the transaction that holds the change (U-63). A `PUT` that repeats the stored
 * state changes nothing and writes nothing — neither the row's `updated_at` nor
 * an entry. A trail full of „changed the language from German to German" would
 * be noise that hides the entries somebody is looking for.
 */
final readonly class SetOwnPreferences
{
    public function __construct(
        private AdminPreferenceStore $store,
        private AuditWriter $audit,
        private Clock $clock,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     */
    public function handle(ActorContext $actor, AdminPreferences $preferences): AdminPreferences
    {
        AdminPolicy::requireOwnPreferencesWrite($actor);

        return $this->store->atomically(function () use ($actor, $preferences): AdminPreferences {
            $before = $this->store->find($actor->username);

            if ($before->equals($preferences)) {
                return $before;
            }

            $this->store->save($actor->username, $preferences, $this->clock->now());

            $this->audit->record(AuditEntry::change(
                $actor,
                'admin.preferences.change',
                'admin',
                $actor->username,
                $before->representation(),
                $preferences->representation(),
            ));

            return $preferences;
        });
    }
}

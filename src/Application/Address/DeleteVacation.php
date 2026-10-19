<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Domain\Access\ActorContext;

/**
 * `DELETE /accounts/{account}/vacation`.
 *
 *  - **ours, with a notice** — the rule set is rendered again without it; an
 *    empty rule set withdraws the script (M3's installer);
 *  - **ours, unreadable** — withdrawn: it is ours, and what it holds cannot be
 *    rendered again;
 *  - **foreign** — only with `take_over`, and then only deactivated, never
 *    deleted (`vacation.foreign_script` otherwise);
 *  - **nothing** — `204`, idempotent.
 */
final readonly class DeleteVacation
{
    public const string OPERATION = 'vacation.delete';

    public function __construct(
        private AccountTarget $accounts,
        private VacationScripts $scripts,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     * @throws \Throwable
     */
    public function handle(ActorContext $actor, string $account, bool $takeOver, ?string $ifMatch): void
    {
        [$record, $identity] = $this->accounts->forDelete($actor, $account);

        $before = $this->scripts->state($record->storedUsername, $identity);
        AddressView::requireMatch(AddressView::vacation($before), $ifMatch, 'The vacation notice');

        $entry = AuditEntry::removal(
            $actor,
            self::OPERATION,
            'vacation',
            $record->storedUsername,
            before: VacationRecord::of($before),
            domain: $record->domain,
        );

        switch ($before->kind) {
            case VacationKind::None:
                return;

            case VacationKind::Active:
                $this->scripts->replace(
                    self::OPERATION,
                    $before,
                    $identity,
                    $before->baseRules()->withVacation(null),
                    false,
                    $entry,
                );

                return;

            case VacationKind::Foreign:
                if (!$takeOver) {
                    throw AddressOperationFailed::foreignScript($record->storedUsername);
                }

                $this->scripts->remove(self::OPERATION, $before, $identity, $entry);

                return;

            case VacationKind::Unreadable:
                $this->scripts->remove(self::OPERATION, $before, $identity, $entry);
        }
    }
}

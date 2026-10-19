<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Sieve\VacationRule;

/**
 * `PUT /accounts/{account}/vacation` — the vacation notice, rendered into the
 * account's Sieve script by M3's renderer and installed by its installer
 * (architecture 9.4, U-102).
 *
 * The rule set is read back from the model line of our own script, the
 * vacation rule replaced, the rest — whatever else a later package keeps
 * there — carried over unchanged, and the script rendered whole. Nothing is
 * patched.
 *
 * **A foreign active script is not replaced** unless the body says
 * `take_over: true` (`vacation.foreign_script`, 409). An unreadable script —
 * ours by its marker, with a model line that does not decode — is ours and is
 * replaced; what it held is shown in the `GET` before.
 *
 * There is no period (A3 open point 7): Sieve `vacation` has none, and a
 * period would need a scheduler that removes the notice.
 */
final readonly class SetVacation
{
    public const string OPERATION = 'vacation.set';

    /** The contract's `maxItems` for `addresses`. */
    public const int ADDRESS_LIMIT = 20;

    public function __construct(
        private AccountTarget $accounts,
        private VacationScripts $scripts,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Exception\DomainError when the notice is malformed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     * @throws \Throwable                              when the audit entry cannot be written
     */
    public function handle(ActorContext $actor, string $account, VacationDraft $draft, ?string $ifMatch): VacationState
    {
        [$record, $identity] = $this->accounts->forWrite($actor, $account);

        $rule = VacationRule::of(
            $draft->message,
            $draft->subject,
            $draft->intervalDays ?? VacationRule::DEFAULT_INTERVAL_DAYS,
            self::addresses($draft->addresses, $identity),
        );

        $before = $this->scripts->state($record->storedUsername, $identity);
        AddressView::requireMatch(AddressView::vacation($before), $ifMatch, 'The vacation notice');

        if ($before->kind === VacationKind::Foreign && !$draft->takeOver) {
            throw AddressOperationFailed::foreignScript($record->storedUsername);
        }

        $rules = $before->baseRules()->withVacation($rule);

        $this->scripts->replace(
            self::OPERATION,
            $before,
            $identity,
            $rules,
            // Foreign active scripts need it (checked above); an inactive
            // foreign script under our name needs it as well (U-113).
            $draft->takeOver,
            $before->kind === VacationKind::Active
                ? AuditEntry::change(
                    $actor,
                    self::OPERATION,
                    'vacation',
                    $record->storedUsername,
                    before: VacationRecord::of($before),
                    after: self::recordOf($rule),
                    domain: $record->domain,
                )
                : AuditEntry::creation(
                    $actor,
                    self::OPERATION,
                    'vacation',
                    $record->storedUsername,
                    after: self::recordOf($rule) + ['replaced' => $before->kind->value],
                    domain: $record->domain,
                ),
        );

        return $this->scripts->state($record->storedUsername, $identity);
    }

    /**
     * @return array<string, mixed>
     */
    private static function recordOf(VacationRule $rule): array
    {
        return [
            'state' => VacationKind::Active->value,
            'message_length' => mb_strlen($rule->message),
            'subject' => $rule->subject,
            'interval_days' => $rule->intervalDays,
            'addresses' => $rule->addressStrings(),
        ];
    }

    /**
     * @param list<string>|null $requested
     *
     * @return list<EmailAddress>
     *
     * @throws InvalidAddress
     */
    private static function addresses(?array $requested, MailboxIdentity $identity): array
    {
        if ($requested === null || $requested === []) {
            return [$identity->address()];
        }

        if (count($requested) > self::ADDRESS_LIMIT) {
            throw InvalidAddress::notAnAddress(sprintf('%d addresses', count($requested)));
        }

        $addresses = [];
        $seen = [];

        foreach ($requested as $value) {
            $address = EmailAddress::parse($value)->toLowerCase();
            $key = $address->toString();

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}

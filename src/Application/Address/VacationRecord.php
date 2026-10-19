<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * What the audit trail records about a vacation notice. **Not the message**:
 * it is the user's text, possibly personal, and the trail keeps who changed
 * what, not what someone wrote to their correspondents. Its length is enough
 * to tell two versions apart.
 */
final class VacationRecord
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(VacationState $state): array
    {
        $rule = $state->rule();

        return [
            'state' => $state->kind->value,
            'message_length' => $rule === null ? null : mb_strlen($rule->message),
            'subject' => $rule?->subject,
            'interval_days' => $rule?->intervalDays,
            'addresses' => $rule?->addressStrings(),
            'script_name' => $state->script?->name,
        ];
    }
}

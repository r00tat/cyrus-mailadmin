<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * What the audit trail records about an alias, a catchall or a forward —
 * the fields a client edits, not the rows.
 */
final class AliasRecord
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function ofAlias(AliasState $state): array
    {
        return [
            'address' => $state->address->toString(),
            'destinations' => $state->destinations()->all(),
            'enabled' => $state->isEnabled(),
            'sender_account' => $state->senderAccount(),
            'rows' => count($state->rows->entries),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ofCatchall(CatchallState $state): array
    {
        return [
            'domain' => $state->domain->toString(),
            'destinations' => $state->destinations()->all(),
            'enabled' => $state->isEnabled(),
            'sender_account' => $state->senderAccount(),
            'rows' => count($state->rows->entries),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ofForwards(ForwardState $state): array
    {
        return [
            'account' => $state->account->storedUsername,
            'destinations' => $state->destinations()->all(),
            'keep_copy' => $state->keepsCopy(),
            'enabled' => $state->isEnabled(),
            'rows' => count($state->rows->entries),
        ];
    }
}

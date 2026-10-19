<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Domain\DomainView;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\ReservedLocalPart;
use Mailadmin\Domain\Model\Virtual\UnknownVirtualEntry;
use Mailadmin\Domain\Model\Virtual\VirtualEntry;

/**
 * A3's representations in the shape the contract describes, and the `ETag`
 * over exactly that shape (U-19) — computed by A1's functions, for the reason
 * `AccountView` gives.
 */
final class AddressView
{
    private function __construct()
    {
    }

    /**
     * One row, whatever its type. `id` is the {@see EntryKey}.
     *
     * @return array<string, mixed>
     */
    public static function entry(VirtualEntry $entry, ClassifiedRows $rows): array
    {
        $row = $entry->row();

        return [
            'id' => EntryKey::of($row),
            'type' => $entry->type()->value,
            'alias' => $row->alias,
            'destinations' => $entry->destinations()->all(),
            'enabled' => $entry->isEnabled(),
            'username' => $row->username,
            'sender_account' => $rows->senderOf($entry),
            'reserved' => self::isReservedAlias($row->alias),
            'unknown_reason' => $entry instanceof UnknownVirtualEntry ? $entry->reason->value : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(ClassifiedRows $rows): array
    {
        return array_map(
            static fn (VirtualEntry $entry): array => self::entry($entry, $rows),
            $rows->entries,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function alias(AliasState $state): array
    {
        return [
            'address' => $state->address->toString(),
            'domain' => $state->address->domain->toString(),
            'destinations' => $state->destinations()->all(),
            'enabled' => $state->isEnabled(),
            'sender_account' => $state->senderAccount(),
            'reserved' => ReservedLocalPart::isReserved($state->address->localPart),
            'requires_confirmation' => ReservedLocalPart::isMandatory($state->address->localPart),
            'editable' => $state->isEditable(),
            'entries' => self::entries($state->rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function catchall(CatchallState $state): array
    {
        return [
            'domain' => $state->domain->toString(),
            'configured' => $state->isConfigured(),
            'destinations' => $state->destinations()->all(),
            'enabled' => $state->isEnabled(),
            'sender_account' => $state->senderAccount(),
            'editable' => $state->isEditable(),
            'entries' => self::entries($state->rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forwards(ForwardState $state): array
    {
        return [
            'account' => $state->account->storedUsername,
            'configured' => $state->isConfigured(),
            'destinations' => $state->destinations()->all(),
            'keep_copy' => $state->keepsCopy(),
            'copy_required' => $state->copyRequired(),
            'enabled' => $state->isEnabled(),
            'editable' => $state->isEditable(),
            'entries' => self::entries($state->rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function vacation(VacationState $state): array
    {
        $rule = $state->rule();

        return [
            'account' => $state->account,
            'state' => $state->kind->value,
            'message' => $rule?->message,
            'subject' => $rule?->subject,
            'interval_days' => $rule?->intervalDays,
            'addresses' => $rule?->addressStrings(),
            'script_name' => $state->script?->name,
            'script' => $state->kind->showsScript() ? $state->script?->content : null,
        ];
    }

    /**
     * @param array<string, mixed> $representation
     */
    public static function etag(array $representation): string
    {
        return DomainView::etag($representation);
    }

    /**
     * `If-Match`, checked when sent (U-55's line for things cheap to set back).
     *
     * @param array<string, mixed> $representation
     *
     * @throws AddressOperationFailed
     */
    public static function requireMatch(array $representation, ?string $ifMatch, string $what): void
    {
        if ($ifMatch === null || trim($ifMatch) === '') {
            return;
        }

        if (!DomainView::matchesEtag($ifMatch, self::etag($representation))) {
            throw AddressOperationFailed::preconditionFailed($what);
        }
    }

    private static function isReservedAlias(string $alias): bool
    {
        if (!EmailAddress::looksLikeAddress($alias)) {
            return false;
        }

        try {
            return ReservedLocalPart::isReserved(EmailAddress::parseStored($alias)->localPart);
        } catch (\Mailadmin\Domain\Exception\DomainError) {
            return false;
        }
    }
}

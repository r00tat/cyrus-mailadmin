<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use DateTimeZone;
use Mailadmin\Application\Domain\DomainView;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Password\PasswordSubject;

/**
 * An {@see AccountRecord} in the shape the contract describes, and the `ETag`
 * over exactly that shape (U-19).
 *
 * The validator is computed the way A1 computes it, by the same functions —
 * {@see DomainView::etag()} and {@see DomainView::matchesEtag()} are not about
 * domains, they are about canonical JSON, and a second copy would be a second
 * chance for the two to disagree.
 */
final class AccountView
{
    private function __construct()
    {
    }

    /**
     * The representation. List and single resource carry the same fields.
     *
     * @return array<string, mixed>
     */
    public static function full(AccountRecord $record): array
    {
        $identity = $record->identity;

        return [
            'id' => $record->storedUsername,
            'domain' => $record->domain->toString(),
            'address' => $identity?->address()->toString(),
            'local_part' => $identity?->localPart->toString(),
            'naming_scheme' => $record->namingScheme()->value,
            'resolvable' => $identity !== null,
            'services' => [
                'imap' => $record->services->imap,
                'pop' => $record->services->pop,
                'sieve' => $record->services->sieve,
                'smtp_auth' => $record->services->smtpAuth,
            ],
            'password_format' => $record->passwordFormat->value,
            // Current *for this table*: bcrypt. Argon2id is current for
            // `adminuser` and fatal here, because crypt(3) cannot read it.
            'password_format_current' => $record->passwordFormat === PasswordSubject::AccountUser->targetFormat(),
            'password_format_weak' => $record->passwordFormat->isWeak(),
            'legacy_prefix' => $record->legacyPrefix,
            // Enforced, unlike `services` (U-127): `allusers` leaves a disabled
            // account out, so it cannot log in anywhere.
            'enabled' => $record->status->enabled,
            'disabled_at' => $record->status->enabled || $record->status->disabledAt === null
                ? null
                : $record->status->disabledAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'disabled_by' => $record->status->enabled || $record->status->disabledBy === ''
                ? null
                : $record->status->disabledBy,
        ];
    }

    /**
     * @return array{limit_kib: int|null, used_kib: int|null}
     */
    public static function quota(Quota $quota): array
    {
        return [
            'limit_kib' => $quota->limitKibibytes(),
            'used_kib' => $quota->usedKibibytes(),
        ];
    }

    public static function etag(AccountRecord $record): string
    {
        return DomainView::etag(self::full($record));
    }

    /**
     * `If-Match`, checked. `$required` decides whether a missing header is
     * `428` or simply nothing to check (U-49, U-55, U-83).
     *
     * @throws AccountOperationFailed
     */
    public static function requireMatch(AccountRecord $current, ?string $ifMatch, bool $required): void
    {
        if ($ifMatch === null || trim($ifMatch) === '') {
            if ($required) {
                throw AccountOperationFailed::preconditionRequired();
            }

            return;
        }

        if (!DomainView::matchesEtag($ifMatch, self::etag($current))) {
            throw AccountOperationFailed::preconditionFailed();
        }
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * The identifier of one `virtual` row in `/domains/{domain}/addresses/{entry}`
 * (U-103).
 *
 * The table has no key, so the identifier is derived from all four fields:
 * the first 32 hex digits of SHA-256 over `alias`, `dest`, `username` and
 * `status`, separated by NUL. It is opaque to a client and stable for exactly
 * as long as the row is unchanged — a row someone else changed meanwhile is no
 * longer found, which is the answer an `If-Match` would have given.
 *
 * It is a fingerprint, not a secret: everything it is computed from is in the
 * same representation.
 */
final class EntryKey
{
    public const int LENGTH = 32;

    private function __construct()
    {
    }

    public static function of(VirtualRow $row): string
    {
        return substr(
            hash('sha256', $row->alias . "\0" . $row->dest . "\0" . $row->username . "\0" . $row->status),
            0,
            self::LENGTH,
        );
    }

    public static function isWellFormed(string $key): bool
    {
        return preg_match('/^[0-9a-f]{' . self::LENGTH . '}$/', $key) === 1;
    }
}

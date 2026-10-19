<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

/**
 * The `ETag` of an administrator, and the `If-Match` comparison that goes with
 * it (U-19).
 *
 * ## Why this is not in the HTTP layer
 *
 * The tag is a hash of {@see AdminAccount::representation()} — a hash of what
 * the API *shows*, not of the database row. U-19 chose that so a column
 * webcyradm writes in parallel does not invalidate a validator a client is
 * holding. It follows that the tag belongs beside the representation: putting
 * the hashing in a responder and the representation here would be two files that
 * have to agree, and the day they stop agreeing every write answers `412`.
 *
 * It also puts the precondition where the decision is. {@see UpdateAdmin} and
 * {@see DeleteAdmin} refuse a stale write themselves rather than trusting a
 * handler to have asked first — a check that lives in the transport is a check
 * a second caller can skip.
 *
 * ## Weak, and compared weakly
 *
 * `W/"<sha256 hex>"`. RFC 9110 asks for strong comparison on `If-Match`, which a
 * weak validator can by definition never satisfy — following that to the letter
 * would mean every tag this API hands out is rejected the moment it comes back.
 * So the `W/` prefix and the quotes are ignored on both sides, and the hash is
 * what is compared.
 */
final class AdminEtag
{
    private function __construct()
    {
    }

    /**
     * Deterministic across processes: a fixed key order and JSON, rather than
     * `serialize()` or `var_export()`, whose output is a PHP version's business.
     */
    public static function of(AdminAccount $admin): string
    {
        return sprintf('W/"%s"', hash('sha256', json_encode(
            $admin->representation(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )));
    }

    /**
     * Does this `If-Match` header value permit writing to `$admin`?
     *
     * `null` or an empty header means yes: the header is optional here
     * (architecture 7.1 — writing methods *accept* it), and a client that does
     * not send one gets last-write-wins.
     *
     * `*` means "as long as it exists". It does: the caller found it before
     * asking.
     */
    public static function allows(?string $header, AdminAccount $admin): bool
    {
        $value = trim($header ?? '');

        if ($value === '') {
            return true;
        }

        $current = self::bare(self::of($admin));

        foreach (explode(',', $value) as $candidate) {
            $tag = self::bare($candidate);

            if ($tag === '*' || hash_equals($current, $tag)) {
                return true;
            }
        }

        return false;
    }

    private static function bare(string $value): string
    {
        $tag = trim($value);

        if (str_starts_with($tag, 'W/')) {
            $tag = substr($tag, 2);
        }

        return trim($tag, '"');
    }
}

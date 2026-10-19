<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * The one place a mailing list is turned into what the API returns — and,
 * because of that, the one place the `ETag` is computed (U-19).
 *
 * The two belong together. U-19 fixes the validator as a hash over **the fields
 * the API delivers**, not over the database row: a column the external tool
 * writes in parallel must not invalidate an administrator's `If-Match`, and a
 * hash taken anywhere else would eventually be taken over something else.
 * Keeping the serialisation and the hash in one function means the value a
 * client received and the value it is compared against cannot drift.
 *
 * ## The two flags
 *
 * `unusable_members` and `address_usable` exist because `lists` is written by an
 * external tool (E-4) and may hold entries this application would not accept —
 * a local user name, a file path, a pipe. Those rows are **shown and marked**,
 * never filtered out and never silently corrected (U-22). Marking them is also
 * what makes the refusal to *add* one comprehensible instead of arbitrary.
 */
final class MailingListView
{
    private function __construct()
    {
    }

    /**
     * @return array{
     *     name: string,
     *     tag: string,
     *     address: string,
     *     members: list<string>,
     *     unusable_members: list<string>,
     *     address_usable: bool,
     * }
     */
    public static function representation(MailingList $list): array
    {
        return [
            'name' => $list->name->toString(),
            'tag' => $list->tag,
            'address' => $list->address,
            'members' => $list->members->members(),
            'unusable_members' => $list->members->unusableMembers(),
            'address_usable' => $list->addressIsUsable(),
        ];
    }

    /**
     * A weak validator, because it is the representation that is being compared
     * and not the bytes of one particular serialisation (U-19).
     */
    public static function etag(MailingList $list): string
    {
        $canonical = json_encode(
            self::representation($list),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return 'W/"' . hash('sha256', $canonical) . '"';
    }

    /**
     * Does a client's `If-Match` value name the representation we are holding?
     *
     * Whitespace-tolerant and weak-comparison by RFC 9110: `W/"x"` and `"x"` are
     * the same validator for this purpose, because the entity we hash is the
     * representation either way. `*` matches anything that exists, which is what
     * RFC 9110 says it means.
     */
    public static function matchesEtag(MailingList $list, string $ifMatch): bool
    {
        $candidate = trim($ifMatch);

        if ($candidate === '*') {
            return true;
        }

        $current = self::normaliseValidator(self::etag($list));

        foreach (explode(',', $candidate) as $value) {
            if (self::normaliseValidator($value) === $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * The contract's collection shape: `{items, meta}` (U-20).
     *
     * @param Page<MailingList> $page
     *
     * @return array{items: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    public static function collection(Page $page): array
    {
        return [
            'items' => array_map(self::representation(...), $page->items),
            'meta' => $page->meta(),
        ];
    }

    private static function normaliseValidator(string $value): string
    {
        $trimmed = trim($value);

        return str_starts_with($trimmed, 'W/') ? trim(substr($trimmed, 2)) : $trimmed;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Mailadmin\Application\MailingList\MailingListChanges;

/**
 * Reading the two body shapes A5 accepts.
 *
 * {@see \Mailadmin\Http\ParsedBody} covers strings; a `members` array needs one
 * more step, and `PATCH` needs something `ParsedBody` deliberately does not
 * offer: the difference between **absent** and **empty**.
 *
 * That difference is the safety catch of the whole package. `{"tag": "team"}`
 * must leave `members` untouched, while `{"members": []}` must empty it — and a
 * reader that turned both into `[]` would silently clear the member list of
 * every list whose tag somebody corrected. `array_key_exists()` rather than
 * `isset()` for the same reason: `isset()` is false for a `null` the client sent
 * on purpose.
 */
final class MailingListBody
{
    private function __construct()
    {
    }

    /**
     * The member list, or `null` when the body did not mention it.
     *
     * Entries that are not strings are dropped rather than rejected. Schema
     * validation has already refused anything but an array of strings (U-9), so
     * this is the narrowing the analyser needs and not a second validator —
     * reporting the same fault twice, in two shapes, is worse than reporting it
     * once.
     *
     * @param array<string, mixed> $body
     *
     * @return list<string>|null
     */
    public static function members(array $body): ?array
    {
        if (!array_key_exists('members', $body)) {
            return null;
        }

        /** @var mixed $value */
        $value = $body['members'];

        if (!is_array($value)) {
            return null;
        }

        $members = [];

        /** @var mixed $member */
        foreach ($value as $member) {
            if (is_string($member)) {
                $members[] = $member;
            }
        }

        return $members;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function changes(array $body): MailingListChanges
    {
        return new MailingListChanges(
            tag: self::optionalText($body, 'tag'),
            name: self::optionalText($body, 'name'),
            members: self::members($body),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalText(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }

        /** @var mixed $value */
        $value = $body[$field];

        return is_string($value) ? $value : null;
    }
}

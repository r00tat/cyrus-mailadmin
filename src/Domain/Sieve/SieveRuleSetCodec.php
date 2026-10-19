<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

use JsonException;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * The rule set as data — written into the script it was rendered into, so that
 * the script can be shown and changed again **without parsing Sieve** (U-102).
 *
 * Architecture 9.4 says: generate, never patch. That leaves the question where
 * the structured model lives between two changes. The answer is in the script
 * itself, on the line after the marker:
 *
 * ```text
 * # mailadmin-managed: v1
 * # mailadmin-model: eyJ2IjoxLCJ2YWNhdGlvbiI6ey…
 * ```
 *
 * The value is base64url (no padding) over canonical JSON. A comment line in
 * Sieve runs to the next CRLF, and base64url has neither CR nor LF nor anything
 * Sieve would read as syntax, so the line cannot change what the script does.
 *
 * Reading it back is **not** parsing the script: only our own line is read, and
 * only in a script that carries our marker. Anything that does not decode into
 * a valid rule set — a hand-edited line, a newer version — is "unreadable", and
 * the use case shows the raw script instead of guessing (U-22).
 *
 * The line describes what the generator was given; it is not proof of what the
 * script does. Someone with ManageSieve access can edit a managed script and
 * keep the line. That is the same trust the marker itself already rests on.
 */
final class SieveRuleSetCodec
{
    public const string MODEL_PREFIX = '# mailadmin-model: ';

    /** The format of the JSON. Bumped when a reader of version 1 would misread it. */
    public const int VERSION = 1;

    private function __construct()
    {
    }

    public static function encode(SieveRuleSet $rules): string
    {
        $json = json_encode(
            self::toArray($rules),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * The rule set the line describes, or `null` when it does not describe one.
     */
    public static function decode(string $encoded): ?SieveRuleSet
    {
        $value = trim($encoded);

        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || ($data['v'] ?? null) !== self::VERSION) {
            return null;
        }

        try {
            return self::fromArray($data);
        } catch (DomainError|\TypeError|\ValueError) {
            return null;
        }
    }

    /**
     * The model line of a script's content, or `null` when there is none.
     *
     * Only the second line is looked at — the one the renderer writes. A line
     * further down could be part of a message body.
     */
    public static function modelOf(string $content): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content, 3);

        if ($lines === false || count($lines) < 2) {
            return null;
        }

        $line = $lines[1];

        return str_starts_with($line, self::MODEL_PREFIX)
            ? substr($line, strlen(self::MODEL_PREFIX))
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(SieveRuleSet $rules): array
    {
        $vacation = $rules->vacation();

        return [
            'v' => self::VERSION,
            'vacation' => $vacation === null ? null : [
                'message' => $vacation->message,
                'subject' => $vacation->subject,
                'days' => $vacation->intervalDays,
                'addresses' => $vacation->addressStrings(),
            ],
            'redirects' => array_map(
                static fn (RedirectRule $redirect): string => $redirect->destination->toString(),
                $rules->redirects(),
            ),
            'keep' => $rules->keepsLocalCopy(),
            'filters' => array_map(
                static fn (FilterRule $filter): array => [
                    'conditions' => array_map(
                        static fn (FilterCondition $condition): array => [
                            'header' => $condition->header,
                            'match' => $condition->matchType->value,
                            'value' => $condition->value,
                        ],
                        $filter->conditions,
                    ),
                    'join' => $filter->join->value,
                    'action' => $filter->action->value,
                    'folder' => $filter->folder,
                    'stop' => $filter->stopAfterMatch,
                ],
                $rules->filters(),
            ),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws DomainError
     * @throws \ValueError
     */
    private static function fromArray(array $data): ?SieveRuleSet
    {
        $redirects = [];

        foreach (self::listOf($data['redirects'] ?? []) as $destination) {
            if (!is_string($destination)) {
                return null;
            }

            $redirects[] = new RedirectRule(EmailAddress::parseStored($destination));
        }

        $filters = [];

        foreach (self::listOf($data['filters'] ?? []) as $filter) {
            $rule = is_array($filter) ? self::filter($filter) : null;

            if ($rule === null) {
                return null;
            }

            $filters[] = $rule;
        }

        $keep = $data['keep'] ?? true;
        $vacation = $data['vacation'] ?? null;

        if (!is_bool($keep) || ($vacation !== null && !is_array($vacation))) {
            return null;
        }

        return SieveRuleSet::of(
            $vacation === null ? null : self::vacation($vacation),
            $redirects,
            $filters,
            $keep,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws DomainError
     */
    private static function vacation(array $data): VacationRule
    {
        $message = $data['message'] ?? null;
        $subject = $data['subject'] ?? null;
        $days = $data['days'] ?? null;

        if (!is_string($message) || ($subject !== null && !is_string($subject)) || !is_int($days)) {
            throw new \ValueError('The vacation part of the model is malformed.');
        }

        $addresses = [];

        foreach (self::listOf($data['addresses'] ?? []) as $address) {
            if (!is_string($address)) {
                throw new \ValueError('A vacation address in the model is not a string.');
            }

            $addresses[] = EmailAddress::parseStored($address);
        }

        return VacationRule::of($message, $subject, $days, $addresses);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws DomainError
     * @throws \ValueError
     */
    private static function filter(array $data): ?FilterRule
    {
        $conditions = [];

        foreach (self::listOf($data['conditions'] ?? []) as $condition) {
            if (!is_array($condition)) {
                return null;
            }

            $header = $condition['header'] ?? null;
            $match = $condition['match'] ?? null;
            $value = $condition['value'] ?? null;

            if (!is_string($header) || !is_string($match) || !is_string($value)) {
                return null;
            }

            $conditions[] = FilterCondition::on($header, MatchType::from($match), $value);
        }

        $join = $data['join'] ?? null;
        $action = $data['action'] ?? null;
        $folder = $data['folder'] ?? null;
        $stop = $data['stop'] ?? true;

        if (!is_string($join) || !is_string($action) || !is_bool($stop)) {
            return null;
        }

        $rule = match (FilterAction::from($action)) {
            FilterAction::FileInto => is_string($folder)
                ? FilterRule::fileInto($folder, ConditionJoin::from($join), ...$conditions)
                : null,
            FilterAction::Discard => FilterRule::discard(ConditionJoin::from($join), ...$conditions),
            FilterAction::Keep => FilterRule::keep(ConditionJoin::from($join), ...$conditions),
        };

        if ($rule === null) {
            return null;
        }

        return $stop ? $rule : $rule->continuingAfterMatch();
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \ValueError('Expected a list in the model.');
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

/**
 * What a new domain gets written into `domain.prefix` (A1, point 1 — U-46).
 *
 * The column is `varchar(50) NOT NULL UNIQUE`. webcyradm used it as a surrogate
 * key for the domain and asked the administrator to invent one; it cannot be
 * removed (E-1), and Postfix does not read it, so the only requirements left are
 * that it exists, that it is unique, and that it fits.
 *
 * **It is therefore derived, not asked for.** A field a person has to fill in
 * with a value nothing reads is a field they will fill in badly, and the legacy
 * interface proved it: prefixes there are arbitrary abbreviations with no
 * relation to anything. Deriving it from the domain name makes a row in `domain`
 * readable on its own, which is the only remaining use the column has.
 *
 * ## The candidates
 *
 * ```text
 * example.test                            -> example.test
 * a-very-long-name.example.test (>50)     -> a-very-long-name.example.te-9f86d081
 * collision                               -> …-<a different 8 hex digits>
 * ```
 *
 * The first candidate is the domain name itself when it fits. Everything after
 * that trades readability for room: 41 characters of the name, a hyphen, and
 * eight hex digits of a SHA-256 over the name and the attempt number. The hash
 * is a spreading function, not a security measure — a prefix is not a secret and
 * nothing authenticates with it.
 *
 * The list is finite on purpose. A caller that exhausts it reports
 * `domain.prefix_taken` rather than looping, because at that point the column
 * holds a value that was not put there by this code and a human should look.
 */
final readonly class LegacyPrefix
{
    /** `domain.prefix` is a `varchar(50)`. */
    public const int MAX_LENGTH = 50;

    /** Characters kept from the domain name. Everything else is dropped. */
    private const string ALLOWED = '/[^a-z0-9.\-]/';

    /** Room for `-` plus eight hex digits. */
    private const int HASH_LENGTH = 8;

    /** How many spellings are offered before the caller gives up. */
    public const int CANDIDATE_COUNT = 8;

    private function __construct()
    {
    }

    /**
     * Every prefix this domain may be given, in preference order.
     *
     * The caller takes the first one that is not already in the table. All of
     * them are non-empty, at most {@see MAX_LENGTH} characters, and made only of
     * lower-case letters, digits, dots and hyphens.
     *
     * @return non-empty-list<string>
     */
    public static function candidatesFor(DomainName $domain): array
    {
        $base = self::sanitise($domain->toString());
        $candidates = [];

        if (strlen($base) <= self::MAX_LENGTH) {
            $candidates[] = $base;
        }

        for ($attempt = 1; count($candidates) < self::CANDIDATE_COUNT; ++$attempt) {
            $candidates[] = self::discriminated($base, $domain->toString(), $attempt);
        }

        /** @var non-empty-list<string> $unique */
        $unique = array_values(array_unique($candidates));

        return $unique;
    }

    /**
     * The first candidate. What a domain gets when nothing collides — which is
     * every case in a database whose prefixes this code wrote.
     */
    public static function preferredFor(DomainName $domain): string
    {
        return self::candidatesFor($domain)[0];
    }

    /**
     * A prefix of the shape this class produces, and short enough for the
     * column.
     *
     * Used to check a value before it is written, never to check one that is
     * already stored: a legacy prefix is whatever webcyradm's user typed, and
     * U-22 keeps such rows visible rather than rejecting them.
     */
    public static function isUsable(string $prefix): bool
    {
        return $prefix !== ''
            && strlen($prefix) <= self::MAX_LENGTH
            && preg_match(self::ALLOWED, $prefix) !== 1;
    }

    private static function discriminated(string $base, string $seed, int $attempt): string
    {
        $room = self::MAX_LENGTH - self::HASH_LENGTH - 1;
        $stem = rtrim(substr($base, 0, $room), '.-');

        if ($stem === '') {
            // A name made entirely of characters the sanitiser drops. The hash
            // still identifies it, which is all the column needs.
            $stem = 'domain';
        }

        return $stem . '-' . substr(hash('sha256', $seed . ':' . $attempt), 0, self::HASH_LENGTH);
    }

    /**
     * @return non-empty-string
     */
    private static function sanitise(string $name): string
    {
        $clean = (string) preg_replace(self::ALLOWED, '', strtolower(trim($name)));
        $clean = trim($clean, '.-');

        return $clean === '' ? 'domain' : $clean;
    }
}

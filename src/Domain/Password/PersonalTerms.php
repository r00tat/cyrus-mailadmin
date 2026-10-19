<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * The words a password must not contain: the user's own name and domain (U-21).
 *
 * `greta.mustermann@example.test` must not choose `Mustermann2026!`. Building
 * that list is a small job with two traps, so it lives in its own type rather
 * than inline in the policy:
 *
 *  - **The parts matter, not only the whole.** `example.test` is rarely typed
 *    into a password; `example` is. So the local part is split at its dots and
 *    the domain at its labels, and every piece becomes a term of its own.
 *  - **Short pieces would reject almost everything.** A term such as `at`, `de`
 *    or the local part `pw` occurs in ordinary words. Terms shorter than
 *    {@see MINIMUM_TERM_LENGTH} are therefore dropped. **Assumption beyond
 *    U-21**, which does not say how the check is done; four characters is the
 *    usual threshold and keeps `.com`, `.net` and two-letter country codes out
 *    of the way.
 *
 * Matching is a case-insensitive substring test on ASCII. `Mustermann2026`
 * matches `mustermann`; `Mu5termann` does not — there is no leetspeak
 * normalisation, and adding one is a change of behaviour that belongs in a
 * decision, not in a bugfix.
 */
final readonly class PersonalTerms
{
    /**
     * Below this length a term is ignored: it would match ordinary words.
     */
    public const int MINIMUM_TERM_LENGTH = 4;

    /**
     * @param list<string> $terms lower-cased, de-duplicated, all long enough
     */
    private function __construct(private array $terms)
    {
    }

    /**
     * No personal context — the caller has none to offer.
     *
     * Used when a password is checked outside the scope of one account, and by
     * {@see PasswordPolicy::assertAcceptable()} when the argument is omitted.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * From arbitrary strings. Each is also split at `.`, `@`, `-` and `_`.
     */
    public static function of(string ...$terms): self
    {
        return new self(self::normalise($terms));
    }

    /**
     * Everything a mailbox owner would reach for: the local part and its dotted
     * pieces, the domain and its labels, and the full address.
     */
    public static function forMailbox(MailboxIdentity $identity): self
    {
        return self::of(
            $identity->localPart->toString(),
            $identity->domain->toString(),
            $identity->address()->toString(),
            $identity->cyrusUsername(),
        );
    }

    public static function forAddress(LocalPart $localPart, DomainName $domain): self
    {
        return self::of(
            $localPart->toString(),
            $domain->toString(),
            $localPart->toString() . '@' . $domain->toString(),
        );
    }

    /**
     * For `adminuser`, which has a login name and no mailbox.
     */
    public static function forAdmin(string $username): self
    {
        return self::of($username);
    }

    public function with(string ...$terms): self
    {
        return new self(self::normalise([...$this->terms, ...$terms]));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->terms;
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * The first term that occurs in `$candidate`, or `null`.
     *
     * Returns the term rather than a boolean so that
     * {@see InvalidPassword::containsPersonalData()} can name it. The term is
     * the user's own address, not their password — safe to put in a message.
     */
    public function matchIn(string $candidate): ?string
    {
        $haystack = strtolower($candidate);

        foreach ($this->terms as $term) {
            if (str_contains($haystack, $term)) {
                return $term;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, string> $raw
     *
     * @return list<string>
     */
    private static function normalise(array $raw): array
    {
        $terms = [];

        foreach ($raw as $value) {
            $lowered = strtolower(trim($value));

            if ($lowered === '') {
                continue;
            }

            $pieces = preg_split('/[.@_+-]+/', $lowered);

            foreach ([$lowered, ...($pieces === false ? [] : $pieces)] as $piece) {
                // Short pieces would match ordinary words: `.com`, `.at`, a
                // local part of `pw`. See the class comment.
                if (strlen($piece) < self::MINIMUM_TERM_LENGTH) {
                    continue;
                }

                // No map keyed by the term: PHP turns a numeric string key into
                // an int, and a local part of `1234` would come back as one.
                if (!in_array($piece, $terms, true)) {
                    $terms[] = $piece;
                }
            }
        }

        return $terms;
    }
}

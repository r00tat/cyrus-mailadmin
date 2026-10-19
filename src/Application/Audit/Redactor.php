<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

use Mailadmin\Log\SecretMaskingProcessor;

/**
 * Removes secrets from the before/after state of an audit entry.
 *
 * S4 words the requirement precisely, and the wording is the design: secrets are
 * **masked, not shortened** — a password field becomes a placeholder, a hash does
 * not appear at all (U-62).
 *
 * | What | What happens | Why |
 * |---|---|---|
 * | `password`, `new_password`, `secret`, `token`, … | value replaced by `[redacted]` | that the field was set is worth recording; the value never is |
 * | `password_hash`, `crypt`, … | **key dropped entirely** | a hash is a credential in a slower form. Even the placeholder would suggest the trail holds one |
 * | `password_format`, `hash_format` | kept | E-13 and U-22 need the *format* of a legacy hash visible. That is metadata, not a secret |
 *
 * ## One vocabulary, not two
 *
 * The list of what counts as a secret key comes from
 * {@see SecretMaskingProcessor::isSecretKey()} — the same one the logger uses. Two
 * lists would be two places to add `passphrase` to, and the second one would be
 * forgotten. This class adds the hash/plaintext distinction on top, which the
 * logger does not need because it masks everything alike.
 *
 * ## The value check is not decoration
 *
 * Key matching handles the fields anyone would think of. It does nothing for
 * `['stored' => '$2y$10$…']`, and a use case that reads a row out of
 * `accountuser` and hands it over whole is exactly how that happens. So values
 * that are *recognisably* a stored credential are dropped whatever they are
 * called: the crypt(3) prefixes, the RFC 2307 `{SCHEME}` prefixes and MySQL's
 * `PASSWORD()` output. This cannot catch everything — nothing can — and it is
 * defence in depth behind the rule that callers pass the API representation and
 * not the row.
 */
final readonly class Redactor
{
    public const string REDACTED = SecretMaskingProcessor::REDACTED;

    /**
     * Substrings that make a key a *hash* rather than merely a secret. Checked
     * only after {@see SecretMaskingProcessor::isSecretKey()} has said yes, so
     * the metadata exceptions it makes (`hash_format`, `password_format`) are
     * already excluded and stay readable.
     *
     * @var list<string>
     */
    private const array HASH_KEY_FRAGMENTS = ['hash', 'crypt'];

    /**
     * Values that are a stored credential no matter what the key says.
     *
     * In order: the crypt(3) family (`$1$` md5crypt, `$2*$` bcrypt, `$5$`/`$6$`
     * SHA-crypt, `$argon2*$`, `$y$` yescrypt), the RFC 2307 scheme prefixes that
     * Cyrus and pam_mysql understand, and MySQL's `PASSWORD()` — 41 characters,
     * a leading `*`, upper-case hex.
     *
     * @var list<string>
     */
    private const array CREDENTIAL_PATTERNS = [
        '/^\$(1|2[abxy]?|5|6|y|argon2(i|d|id))\$/',
        '/^\{(CRYPT|MD5|SMD5|SHA|SSHA|SHA256|SSHA256|SHA512|SSHA512|PLAIN|CLEARTEXT)\}/i',
        '/^\*[0-9A-F]{40}$/',
    ];

    /**
     * The same limit the log processor uses. A structure deeper than this is
     * dropped rather than walked, so that neither a cycle nor a pathological
     * nesting can stall a write or hide a secret below the floor.
     */
    private const int MAX_DEPTH = 8;

    /**
     * @param array<string, mixed>|null $state
     *
     * @return array<string, mixed>|null `null` stays `null` — "there was nothing" (U-61)
     */
    public function apply(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }

        /** @var array<string, mixed> $redacted */
        $redacted = $this->redactArray($state, 0);

        return $redacted;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => 'maximum audit state depth reached'];
        }

        $result = [];

        /** @var mixed $value */
        foreach ($values as $key => $value) {
            if (is_string($key) && SecretMaskingProcessor::isSecretKey($key)) {
                if (self::isHashKey($key)) {
                    // Dropped, not masked: S4 asks for hashes not to appear.
                    continue;
                }

                $result[$key] = self::REDACTED;

                continue;
            }

            if (self::looksLikeStoredCredential($value)) {
                continue;
            }

            $result[$key] = $this->redactValue($value, $depth);
        }

        return $result;
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->redactArray($value, $depth + 1);
        }

        if (is_object($value)) {
            // Not traversed, for the reason the log processor gives: a value
            // object holding a hash would otherwise be serialised property by
            // property. Callers pass the API representation, which is scalars.
            return $value::class;
        }

        if (is_resource($value)) {
            return 'resource';
        }

        return $value;
    }

    private static function isHashKey(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::HASH_KEY_FRAGMENTS as $fragment) {
            if (str_contains($needle, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function looksLikeStoredCredential(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        foreach (self::CREDENTIAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}

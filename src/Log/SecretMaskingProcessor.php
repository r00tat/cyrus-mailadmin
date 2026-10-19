<?php

declare(strict_types=1);

namespace Mailadmin\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes secrets from log context and extra data.
 *
 * CLAUDE.md is unconditional here: passwords, hashes and tokens are never
 * logged, not even truncated. Relying on every call site to remember that is
 * how secrets end up in log files, so the guarantee is enforced once, centrally,
 * for every record that passes through Monolog.
 *
 * Matching is on the *key*, case-insensitively, by substring against a short
 * vocabulary. Substring matching is deliberate: `password`, `new_password`,
 * `db_password` and `passwordHash` must all be caught without maintaining a
 * list that grows with every new package.
 *
 * Two limits are worth knowing when writing a log call:
 *
 *  - The **message** is not inspected. Never interpolate a secret into it.
 *  - **Objects** in the context are replaced by their class name rather than
 *    traversed. Monolog would otherwise serialise their properties, and a value
 *    object holding a hash would leak. Pass scalars.
 */
final class SecretMaskingProcessor implements ProcessorInterface
{
    public const string REDACTED = '[redacted]';

    /**
     * Substrings that mark a key as secret. Lower case; the key is lowercased
     * before comparison.
     *
     * @var list<string>
     */
    private const array SECRET_KEY_FRAGMENTS = [
        'apikey',
        'api_key',
        'authorization',
        'bearer',
        'cookie',
        'credential',
        'crypt',
        'hash',
        'passphrase',
        'passwd',
        'password',
        'privatekey',
        'private_key',
        'secret',
        'session_id',
        'token',
    ];

    /**
     * The only exceptions to the rule above, matched exactly and case-insensitively.
     *
     * Each of these names a *format or algorithm*, never a value — and each is
     * something the application deliberately makes visible: U-22 requires the hash
     * format of an existing account to be shown rather than hidden, and a log line
     * that says `[redacted]` instead of `des-crypt` is the opposite of what E-13
     * asks for.
     *
     * Adding an entry here needs the same care as removing a fragment above. A key
     * belongs on this list only if it can never, under any caller, carry the secret
     * itself.
     *
     * @var list<string>
     */
    private const array METADATA_KEYS = [
        'hash_algorithm',
        'hash_format',
        'password_format',
        'token_type',
    ];

    /**
     * Deeper nesting is not traversed but dropped, so that a cyclic or
     * pathologically deep structure can neither hide a secret below the limit
     * nor stall the logger.
     */
    private const int MAX_DEPTH = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->maskArray($record->context, 0),
            extra: $this->maskArray($record->extra, 0),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function maskArray(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => 'maximum log context depth reached'];
        }

        $masked = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $masked[$key] = self::REDACTED;

                continue;
            }

            $masked[$key] = $this->maskValue($value, $depth);
        }

        return $masked;
    }

    private function maskValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->maskArray($value, $depth + 1);
        }

        if (is_object($value)) {
            // Not traversed on purpose — see the class comment.
            return $value::class;
        }

        if (is_resource($value)) {
            return 'resource';
        }

        return $value;
    }

    public static function isSecretKey(string $key): bool
    {
        $needle = strtolower($key);

        if (in_array($needle, self::METADATA_KEYS, true)) {
            return false;
        }

        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($needle, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

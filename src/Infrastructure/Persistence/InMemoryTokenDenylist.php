<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenDenylist;

/**
 * The denylist in an array, keyed the same way the table is: `subject` then
 * `jti`, with the empty `jti` standing for "all of this subject's tokens".
 *
 * The widening upsert of {@see SqlTokenDenylist} is reproduced — a second
 * revocation may push the cutoff later but never earlier. Without that, a test
 * would pass here and a re-revocation would silently un-revoke tokens against
 * the real table.
 */
final class InMemoryTokenDenylist implements TokenDenylist
{
    private const string SUBJECT_WIDE = '';

    /** @var array<string, array<string, array{cutoff: DateTimeImmutable, expires: DateTimeImmutable, reason: string}>> */
    private array $rows = [];

    public function denyToken(TokenClaims $claims, string $reason): void
    {
        $this->write($claims->subject, $claims->jti, $claims->issuedAt, $claims->expiresAt, $reason);
    }

    public function denySubject(
        string $subject,
        DateTimeImmutable $issuedBefore,
        DateTimeImmutable $expiresAt,
        string $reason,
    ): void {
        $this->write($subject, self::SUBJECT_WIDE, $issuedBefore, $expiresAt, $reason);
    }

    public function isDenied(TokenClaims $claims): bool
    {
        $forSubject = $this->rows[$claims->subject] ?? [];

        if (isset($forSubject[$claims->jti])) {
            return true;
        }

        $wide = $forSubject[self::SUBJECT_WIDE] ?? null;

        return $wide !== null && $claims->issuedAt <= $wide['cutoff'];
    }

    public function prune(DateTimeImmutable $before): int
    {
        $removed = 0;

        foreach ($this->rows as $subject => $entries) {
            foreach ($entries as $jti => $entry) {
                if ($entry['expires'] <= $before) {
                    unset($this->rows[$subject][$jti]);
                    ++$removed;
                }
            }

            if ($this->rows[$subject] === []) {
                unset($this->rows[$subject]);
            }
        }

        return $removed;
    }

    private function write(
        string $subject,
        string $jti,
        DateTimeImmutable $cutoff,
        DateTimeImmutable $expires,
        string $reason,
    ): void {
        $existing = $this->rows[$subject][$jti] ?? null;

        $this->rows[$subject][$jti] = [
            'cutoff' => $existing === null ? $cutoff : max($existing['cutoff'], $cutoff),
            'expires' => $existing === null ? $expires : max($existing['expires'], $expires),
            'reason' => $reason,
        ];
    }
}

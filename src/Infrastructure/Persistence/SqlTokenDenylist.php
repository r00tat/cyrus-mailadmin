<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Runtime\Clock;

/**
 * `mailadmin_token_denylist`.
 *
 * ## One read per authenticated request
 *
 * {@see isDenied()} is a single range read over the primary key
 * `(subject, jti)`, returning at most two rows — the token's own, and the
 * subject-wide one. Both kinds are answered by the same query, which is the
 * point of storing them in one table:
 *
 *     SELECT jti, issued_before FROM mailadmin_token_denylist
 *      WHERE subject = :sub AND jti IN ('', :jti)
 *
 * The cutoff comparison is done in PHP rather than in SQL so that the row's
 * meaning is decided in one readable place. It is at most two rows; there is
 * nothing to gain by pushing it down.
 *
 * ## Writes are upserts
 *
 * Signing out twice, or revoking a subject twice, writes the same key again.
 * `ON DUPLICATE KEY UPDATE` keeps the **later** of the two cutoffs and the later
 * expiry, so a second revocation can widen the row but never narrow it — a
 * narrowing upsert would quietly un-revoke tokens.
 */
final readonly class SqlTokenDenylist implements TokenDenylist
{
    /** The `jti` value that means "every token of this subject". */
    private const string SUBJECT_WIDE = '';

    public function __construct(
        private Connection $connection,
        private Clock $clock,
    ) {
    }

    public function denyToken(TokenClaims $claims, string $reason): void
    {
        $this->write(
            subject: $claims->subject,
            jti: $claims->jti,
            issuedBefore: $claims->issuedAt,
            expiresAt: $claims->expiresAt,
            reason: $reason,
        );
    }

    public function denySubject(
        string $subject,
        DateTimeImmutable $issuedBefore,
        DateTimeImmutable $expiresAt,
        string $reason,
    ): void {
        $this->write(
            subject: $subject,
            jti: self::SUBJECT_WIDE,
            issuedBefore: $issuedBefore,
            expiresAt: $expiresAt,
            reason: $reason,
        );
    }

    public function isDenied(TokenClaims $claims): bool
    {
        $rows = $this->connection->select(
            'SELECT `jti`, `issued_before` FROM `mailadmin_token_denylist` '
            . 'WHERE `subject` = :subject AND `jti` IN (:wide, :jti)',
            [
                'subject' => $claims->subject,
                'wide' => self::SUBJECT_WIDE,
                'jti' => $claims->jti,
            ],
        );

        foreach ($rows as $row) {
            $jti = is_scalar($row['jti'] ?? null) ? (string) $row['jti'] : '';

            if ($jti !== self::SUBJECT_WIDE) {
                // The token's own row. Its presence is the whole answer.
                return true;
            }

            $cutoff = self::time($row['issued_before'] ?? null);

            if ($cutoff !== null && $claims->issuedAt <= $cutoff) {
                return true;
            }
        }

        return false;
    }

    public function prune(DateTimeImmutable $before): int
    {
        return $this->connection->execute(
            'DELETE FROM `mailadmin_token_denylist` WHERE `expires_at` <= :before',
            ['before' => self::stamp($before)],
        );
    }

    private function write(
        string $subject,
        string $jti,
        DateTimeImmutable $issuedBefore,
        DateTimeImmutable $expiresAt,
        string $reason,
    ): void {
        $this->connection->execute(
            'INSERT INTO `mailadmin_token_denylist` '
            . '(`subject`, `jti`, `issued_before`, `expires_at`, `reason`, `created_at`) '
            . 'VALUES (:subject, :jti, :issued, :expires, :reason, :created) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`issued_before` = GREATEST(`issued_before`, VALUES(`issued_before`)), '
            . '`expires_at` = GREATEST(`expires_at`, VALUES(`expires_at`)), '
            . '`reason` = VALUES(`reason`)',
            [
                'subject' => $subject,
                'jti' => $jti,
                'issued' => self::stamp($issuedBefore),
                'expires' => self::stamp($expiresAt),
                'reason' => $reason,
                'created' => self::stamp($this->clock->now()),
            ],
        );
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }

    private static function stamp(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

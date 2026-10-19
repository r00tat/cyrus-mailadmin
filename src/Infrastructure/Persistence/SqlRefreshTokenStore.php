<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Infrastructure\Security\RefreshTokenRecord;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;

/**
 * `mailadmin_refresh_token`.
 *
 * ## The one statement that carries the security property
 *
 * {@see consume()} is a single conditional `UPDATE`:
 *
 *     UPDATE mailadmin_refresh_token
 *        SET consumed_at = :at
 *      WHERE token_hash = :hash
 *        AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > :at
 *
 * and the caller learns from the affected-row count whether *it* was the one
 * that consumed the token. The database's row lock does the arbitration, so two
 * requests arriving with the same token — a retried fetch, or a thief racing the
 * owner — cannot both be told yes. A `SELECT` followed by an `UPDATE` would let
 * exactly that through, and the window is milliseconds wide, which is to say
 * wide enough.
 *
 * **This assumes InnoDB.** On MyISAM the `UPDATE` still counts rows correctly
 * because it is one statement, but nothing here is transactional. The
 * `mailadmin_` tables are created as InnoDB by their migrations; `bin/db-check.php`
 * reports what the legacy tables are (O-11).
 */
final readonly class SqlRefreshTokenStore implements RefreshTokenStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function store(RefreshTokenRecord $record): void
    {
        $this->connection->execute(
            'INSERT INTO `mailadmin_refresh_token` '
            . '(`token_hash`, `family`, `subject`, `issued_at`, `expires_at`, `consumed_at`, `revoked_at`) '
            . 'VALUES (:hash, :family, :subject, :issued, :expires, :consumed, :revoked)',
            [
                'hash' => $record->tokenHash,
                'family' => $record->family,
                'subject' => $record->subject,
                'issued' => self::stamp($record->issuedAt),
                'expires' => self::stamp($record->expiresAt),
                'consumed' => $record->consumedAt === null ? null : self::stamp($record->consumedAt),
                'revoked' => $record->revokedAt === null ? null : self::stamp($record->revokedAt),
            ],
        );
    }

    public function find(string $tokenHash): ?RefreshTokenRecord
    {
        $row = $this->connection->selectOne(
            'SELECT `token_hash`, `family`, `subject`, `issued_at`, `expires_at`, `consumed_at`, `revoked_at` '
            . 'FROM `mailadmin_refresh_token` WHERE `token_hash` = :hash',
            ['hash' => $tokenHash],
        );

        return $row === null ? null : self::toRecord($row);
    }

    public function consume(string $tokenHash, DateTimeImmutable $at): bool
    {
        // `:at` and `:still_valid_at` carry the same value and have to be two
        // placeholders all the same: with PDO::ATTR_EMULATE_PREPARES turned off
        // — which CLAUDE.md rule 2 requires — the driver binds by position, and
        // a named placeholder used twice is "Invalid parameter number". The
        // emulated driver accepts it, so this only shows up against a real
        // server.
        $affected = $this->connection->execute(
            'UPDATE `mailadmin_refresh_token` SET `consumed_at` = :at '
            . 'WHERE `token_hash` = :hash AND `consumed_at` IS NULL AND `revoked_at` IS NULL '
            . 'AND `expires_at` > :still_valid_at',
            [
                'at' => self::stamp($at),
                'hash' => $tokenHash,
                'still_valid_at' => self::stamp($at),
            ],
        );

        return $affected === 1;
    }

    public function revokeFamily(string $family, DateTimeImmutable $at): int
    {
        return $this->connection->execute(
            'UPDATE `mailadmin_refresh_token` SET `revoked_at` = :at '
            . 'WHERE `family` = :family AND `revoked_at` IS NULL',
            ['at' => self::stamp($at), 'family' => $family],
        );
    }

    public function revokeSubject(string $subject, DateTimeImmutable $at): int
    {
        return $this->connection->execute(
            'UPDATE `mailadmin_refresh_token` SET `revoked_at` = :at '
            . 'WHERE `subject` = :subject AND `revoked_at` IS NULL',
            ['at' => self::stamp($at), 'subject' => $subject],
        );
    }

    public function prune(DateTimeImmutable $before): int
    {
        return $this->connection->execute(
            'DELETE FROM `mailadmin_refresh_token` WHERE `expires_at` <= :before',
            ['before' => self::stamp($before)],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toRecord(array $row): RefreshTokenRecord
    {
        return new RefreshTokenRecord(
            tokenHash: self::text($row['token_hash'] ?? null),
            family: self::text($row['family'] ?? null),
            subject: self::text($row['subject'] ?? null),
            issuedAt: self::time($row['issued_at'] ?? null) ?? self::epoch(),
            expiresAt: self::time($row['expires_at'] ?? null) ?? self::epoch(),
            consumedAt: self::time($row['consumed_at'] ?? null),
            revokedAt: self::time($row['revoked_at'] ?? null),
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * DATETIME columns hold UTC (U-14) and come back as `Y-m-d H:i:s` with no
     * zone, so the zone is supplied here rather than inherited from the server.
     */
    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC'),
        );

        return $parsed === false ? null : $parsed;
    }

    /**
     * An unreadable timestamp becomes the epoch, which makes the record expired
     * and unusable. The alternative — treating it as "now" — would make a broken
     * row into a valid token.
     */
    private static function epoch(): DateTimeImmutable
    {
        return new DateTimeImmutable('@0');
    }

    private static function stamp(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

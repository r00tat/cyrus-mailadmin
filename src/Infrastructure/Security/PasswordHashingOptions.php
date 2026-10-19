<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Mailadmin\Config\Config;

/**
 * The cost parameters for the two target formats.
 *
 * **What is configurable is the cost, never the algorithm.** There is no
 * environment variable that picks a hashing scheme, because a deployment that
 * set `accountuser` to Argon2id would break IMAP and SMTP AUTH for every user
 * (`CLAUDE.md`, E-3). The algorithm comes from
 * {@see \Mailadmin\Domain\Password\PasswordSubject}, which is a closed enum.
 *
 * **bcrypt cost 10, the PHP default.** Deliberate, and the number most likely
 * to want changing after a measurement (S1, open question 1): `pam_mysql`
 * verifies this hash on **every** IMAP and SMTP login, and a POP3 client that
 * polls every minute pays it every minute. Cost 11 doubles that. The floor of
 * {@see MINIMUM_BCRYPT_COST} keeps a deployment from tuning the cost down to
 * something that no longer protects the hash.
 *
 * **Argon2id at PHP's defaults** — 64 MiB, four passes, one thread. Nothing
 * polls `adminuser`, so there is no reason to go below what the language
 * suggests, and 64 MiB is above the OWASP recommendation of 19–46 MiB.
 */
final readonly class PasswordHashingOptions
{
    public const string BCRYPT_COST = 'MAILADMIN_PASSWORD_BCRYPT_COST';
    public const string ARGON2_MEMORY_KIB = 'MAILADMIN_PASSWORD_ARGON2_MEMORY_KIB';
    public const string ARGON2_TIME_COST = 'MAILADMIN_PASSWORD_ARGON2_TIME_COST';
    public const string ARGON2_THREADS = 'MAILADMIN_PASSWORD_ARGON2_THREADS';

    public const int DEFAULT_BCRYPT_COST = 10;

    /** Never below PHP's own default: a lower cost is a downgrade, not a tuning. */
    public const int MINIMUM_BCRYPT_COST = 10;

    /** Beyond this an IMAP login takes seconds; the limit is `crypt(3)`'s own 31. */
    public const int MAXIMUM_BCRYPT_COST = 15;

    public const int DEFAULT_ARGON2_MEMORY_KIB = 65536;
    public const int MINIMUM_ARGON2_MEMORY_KIB = 19456;
    public const int DEFAULT_ARGON2_TIME_COST = 4;
    public const int MINIMUM_ARGON2_TIME_COST = 2;
    public const int DEFAULT_ARGON2_THREADS = 1;
    public const int MAXIMUM_ARGON2_THREADS = 8;

    private function __construct(
        private int $bcryptCost,
        private int $argon2MemoryKib,
        private int $argon2TimeCost,
        private int $argon2Threads,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            self::DEFAULT_BCRYPT_COST,
            self::DEFAULT_ARGON2_MEMORY_KIB,
            self::DEFAULT_ARGON2_TIME_COST,
            self::DEFAULT_ARGON2_THREADS,
        );
    }

    /**
     * Reads the four variables, clamping each into its documented range.
     *
     * Clamping rather than throwing: a typo in a cost parameter must not stop
     * the application from starting, and the clamped value is still safe. The
     * floors are the part that matters — they are what a deployment cannot tune
     * away.
     */
    public static function fromConfig(Config $config): self
    {
        return new self(
            self::clamp(
                $config->intOrDefault(self::BCRYPT_COST, self::DEFAULT_BCRYPT_COST),
                self::MINIMUM_BCRYPT_COST,
                self::MAXIMUM_BCRYPT_COST,
            ),
            self::clamp(
                $config->intOrDefault(self::ARGON2_MEMORY_KIB, self::DEFAULT_ARGON2_MEMORY_KIB),
                self::MINIMUM_ARGON2_MEMORY_KIB,
                PHP_INT_MAX,
            ),
            self::clamp(
                $config->intOrDefault(self::ARGON2_TIME_COST, self::DEFAULT_ARGON2_TIME_COST),
                self::MINIMUM_ARGON2_TIME_COST,
                PHP_INT_MAX,
            ),
            self::clamp(
                $config->intOrDefault(self::ARGON2_THREADS, self::DEFAULT_ARGON2_THREADS),
                1,
                self::MAXIMUM_ARGON2_THREADS,
            ),
        );
    }

    /**
     * For tests and for the cheap parameters an integration run wants.
     */
    public static function of(
        int $bcryptCost = self::DEFAULT_BCRYPT_COST,
        int $argon2MemoryKib = self::DEFAULT_ARGON2_MEMORY_KIB,
        int $argon2TimeCost = self::DEFAULT_ARGON2_TIME_COST,
        int $argon2Threads = self::DEFAULT_ARGON2_THREADS,
    ): self {
        return new self($bcryptCost, $argon2MemoryKib, $argon2TimeCost, $argon2Threads);
    }

    public function bcryptCost(): int
    {
        return $this->bcryptCost;
    }

    /**
     * @return array{cost: int}
     */
    public function bcryptOptions(): array
    {
        return ['cost' => $this->bcryptCost];
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    public function argon2Options(): array
    {
        return [
            'memory_cost' => $this->argon2MemoryKib,
            'time_cost' => $this->argon2TimeCost,
            'threads' => $this->argon2Threads,
        ];
    }

    private static function clamp(int $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, $value));
    }
}

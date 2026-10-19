<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

/**
 * The four service flags on `accountuser`: `imap`, `pop`, `sieve`, `smtpauth`.
 *
 * The columns are `int` and the legacy code wrote `1` and `0`. Anything other
 * than `0` counts as enabled here — a row with `2` is still a row that lets the
 * user in, and pretending otherwise in the interface would be a lie about the
 * running system.
 */
final readonly class AccountServices
{
    public function __construct(
        public bool $imap = true,
        public bool $pop = true,
        public bool $sieve = true,
        public bool $smtpAuth = true,
    ) {
    }

    public static function all(): self
    {
        return new self(true, true, true, true);
    }

    public static function none(): self
    {
        return new self(false, false, false, false);
    }

    public static function fromLegacyFlags(int $imap, int $pop, int $sieve, int $smtpAuth): self
    {
        return new self($imap !== 0, $pop !== 0, $sieve !== 0, $smtpAuth !== 0);
    }

    /**
     * @return array{imap: int, pop: int, sieve: int, smtpauth: int}
     */
    public function toLegacyFlags(): array
    {
        return [
            'imap' => $this->imap ? 1 : 0,
            'pop' => $this->pop ? 1 : 0,
            'sieve' => $this->sieve ? 1 : 0,
            'smtpauth' => $this->smtpAuth ? 1 : 0,
        ];
    }

    /**
     * Can the user still reach their mail at all? `accountuser` has no status
     * column (A2, open point 2), so „everything off" is the closest the legacy
     * schema comes to a disabled account.
     */
    public function allowsAnyAccess(): bool
    {
        return $this->imap || $this->pop || $this->sieve || $this->smtpAuth;
    }

    public function equals(self $other): bool
    {
        return $this->imap === $other->imap
            && $this->pop === $other->pop
            && $this->sieve === $other->sieve
            && $this->smtpAuth === $other->smtpAuth;
    }
}

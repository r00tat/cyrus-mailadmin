<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

use Mailadmin\Domain\Exception\InvalidSieveRule;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * An out-of-office reply (RFC 5230 `vacation`).
 *
 * `:days` says how long the same sender is spared a second reply. The legacy
 * system hard-wired `1`, which means a daily auto-reply to anyone who writes
 * daily; the default here is seven, and the value is part of the rule so that
 * A3 can offer it (M3, open point 3).
 *
 * There is no start and end date: Sieve `vacation` has none. Keeping a period
 * would mean a scheduled job that removes the script — a moving part the system
 * does not otherwise have (M3, open point 6). A3 decides; the model does not
 * pretend to have it.
 */
final readonly class VacationRule
{
    public const int DEFAULT_INTERVAL_DAYS = 7;

    /** Cyrus rejects an interval beyond this. */
    public const int MAX_INTERVAL_DAYS = 60;

    /**
     * @param list<EmailAddress> $addresses further addresses of the owner that
     *                                      should also trigger a reply
     */
    private function __construct(
        public string $message,
        public ?string $subject,
        public int $intervalDays,
        public array $addresses,
    ) {
    }

    /**
     * @param list<EmailAddress> $addresses
     *
     * @throws InvalidSieveRule
     */
    public static function of(
        string $message,
        ?string $subject = null,
        int $intervalDays = self::DEFAULT_INTERVAL_DAYS,
        array $addresses = [],
    ): self {
        if (trim($message) === '') {
            throw InvalidSieveRule::emptyVacationMessage();
        }

        if ($intervalDays < 1 || $intervalDays > self::MAX_INTERVAL_DAYS) {
            throw InvalidSieveRule::vacationIntervalOutOfRange(
                $intervalDays,
                self::MAX_INTERVAL_DAYS,
            );
        }

        $trimmedSubject = $subject === null ? null : trim($subject);

        // A line break inside the quoted `:subject` is legal Sieve, and Cyrus
        // would carry it into the header of every auto-reply (U-114).
        if ($trimmedSubject !== null && preg_match('/[\x00-\x1F\x7F]/', $trimmedSubject) === 1) {
            throw InvalidSieveRule::unusableVacationSubject();
        }

        return new self(
            $message,
            $trimmedSubject === '' ? null : $trimmedSubject,
            $intervalDays,
            array_values($addresses),
        );
    }

    public function hasSubject(): bool
    {
        return $this->subject !== null;
    }

    /**
     * @return list<string>
     */
    public function addressStrings(): array
    {
        $strings = [];

        foreach ($this->addresses as $address) {
            $strings[] = $address->toString();
        }

        return $strings;
    }
}

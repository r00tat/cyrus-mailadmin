<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use DateTimeImmutable;

/**
 * A freshly issued access token: the string to hand out, and the two facts the
 * caller needs about it.
 *
 * `jti` is kept alongside because the sign-out path needs it and decoding the
 * token again to find it would be silly. `expiresAt` is kept because the
 * response states `expires_in`, and computing it from the token would mean
 * parsing what we just wrote.
 *
 * The token string itself is a bearer credential for the next hour. It is
 * therefore hidden from `__debugInfo()` — an object dump in a log line is not a
 * place for something that grants administrator access.
 */
final readonly class AccessToken
{
    public function __construct(
        public string $value,
        public string $jti,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    public function expiresIn(DateTimeImmutable $now): int
    {
        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'value' => '[redacted]',
            'jti' => $this->jti,
            'expiresAt' => $this->expiresAt->format(DATE_ATOM),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * The body of `PUT /domains/{domain}/catchall`. `sender_account` is optional,
 * and "not sent" keeps the current sending account while "sent as `null`"
 * removes it — hence {@see $senderAccountGiven}.
 */
final readonly class CatchallChange
{
    /**
     * @param array<array-key, mixed> $destinations
     */
    public function __construct(
        public array $destinations,
        public bool $enabled = true,
        public bool $senderAccountGiven = false,
        public ?string $senderAccount = null,
    ) {
    }
}

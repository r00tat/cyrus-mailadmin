<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * The body of `PATCH /aliases/{alias}`. A field that is `null` was not sent
 * and stays as it is — except `sender_account`, where "not sent" and "sent as
 * `null`" mean different things, hence {@see $senderAccountGiven}.
 */
final readonly class AliasChange
{
    /**
     * @param array<array-key, mixed>|null $destinations
     */
    public function __construct(
        public ?array $destinations = null,
        public bool $senderAccountGiven = false,
        public ?string $senderAccount = null,
        public ?bool $enabled = null,
        public bool $confirmReserved = false,
    ) {
    }
}

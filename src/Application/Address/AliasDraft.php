<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * The body of `POST /domains/{domain}/aliases`, as the handler read it. The
 * values are checked by the use case, not here.
 */
final readonly class AliasDraft
{
    /**
     * @param array<array-key, mixed> $destinations
     */
    public function __construct(
        public string $localPart,
        public array $destinations,
        public ?string $senderAccount = null,
        public bool $enabled = true,
    ) {
    }
}

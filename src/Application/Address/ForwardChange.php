<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * The body of `PUT /accounts/{account}/forwards`.
 */
final readonly class ForwardChange
{
    /**
     * @param array<array-key, mixed> $destinations
     */
    public function __construct(
        public array $destinations,
        public bool $keepCopy = true,
        public bool $enabled = true,
    ) {
    }
}

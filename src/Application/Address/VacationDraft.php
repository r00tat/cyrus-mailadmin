<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

/**
 * The body of `PUT /accounts/{account}/vacation`.
 */
final readonly class VacationDraft
{
    /**
     * @param list<string>|null $addresses `null`: the account's own address
     */
    public function __construct(
        public string $message,
        public ?string $subject = null,
        public ?int $intervalDays = null,
        public ?array $addresses = null,
        public bool $takeOver = false,
    ) {
    }
}

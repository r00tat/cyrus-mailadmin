<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * One disagreement between the database and the mail store, or inside the
 * database.
 *
 * `subject` is the thing that is wrong, spelled as it is stored — a mailbox
 * name, a user name, an alias. `related` is the other half, when there is one:
 * the account a shadow mailbox shadows, the destination that leads nowhere.
 * `reason` is a stable `snake_case` key, never prose.
 */
final readonly class Finding
{
    public function __construct(
        public FindingCategory $category,
        public string $subject,
        public ?string $domain = null,
        public ?string $related = null,
        public ?string $reason = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toRepresentation(): array
    {
        return [
            'category' => $this->category->value,
            'subject' => $this->subject,
            'domain' => $this->domain,
            'related' => $this->related,
            'reason' => $this->reason,
        ];
    }
}

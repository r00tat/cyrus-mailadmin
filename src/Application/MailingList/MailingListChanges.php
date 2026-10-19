<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

/**
 * What a `PATCH /lists/{listName}` asked to change — and, by omission, what it
 * asked to leave alone.
 *
 * `null` means "not mentioned", which is not the same as "set to empty". The
 * distinction is the safety catch for the whole package: `members` is written by
 * an external tool and read by the configuration management (E-4), so a request
 * that only corrects a tag must not go near it. Modelling absence as `null`
 * rather than as an empty array means the difference is impossible to lose
 * between the handler and the repository.
 *
 * The name is not here. A list is identified by its name (U-60) and renaming it
 * is a delete and a create, so that the URL and the key the configuration
 * management reads can never mean two different lists.
 */
final readonly class MailingListChanges
{
    /**
     * @param list<string>|null $members
     */
    public function __construct(
        public ?string $tag = null,
        public ?string $address = null,
        public ?array $members = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->tag === null && $this->address === null && $this->members === null;
    }
}

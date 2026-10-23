<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

/**
 * What a `PATCH` of a mailing list asked to change — and, by omission, what it
 * asked to leave alone.
 *
 * `null` means "not mentioned", which is not the same as "set to empty". The
 * distinction is the safety catch for the whole package: `members` is read by
 * the delivery script at delivery time, so a request that only corrects a tag
 * must not go near it. Modelling absence as `null` rather than as an empty
 * array means the difference is impossible to lose between the handler and the
 * repository.
 *
 * **The address is not here.** A list is identified by its address (spec 4.3,
 * "Identität"), and the address is immutable: changing it is a delete and a
 * create, so that the URL of a list, its `virtual` row and the key the pipe
 * script reads can never mean two different lists. The display name is free
 * text and may change.
 */
final readonly class MailingListChanges
{
    /**
     * @param list<string>|null $members
     */
    public function __construct(
        public ?string $name = null,
        public ?string $tag = null,
        public ?array $members = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->name === null && $this->tag === null && $this->members === null;
    }
}

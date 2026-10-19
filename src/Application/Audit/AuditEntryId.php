<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

/**
 * The identifier of an entry that has been written.
 *
 * It exists for one purpose: linking the parts of a batch to the operation they
 * belong to (S4, open point 3). A domain rename walks every account in Cyrus, so
 * it writes one entry for the operation and one per account, each carrying the
 * operation's id:
 *
 * ```php
 * $operation = $this->audit->record(AuditEntry::change($actor, 'domain.rename', …));
 *
 * foreach ($accounts as $account) {
 *     $this->audit->record(
 *         AuditEntry::change($actor, 'account.rename', …)->partOf($operation),
 *     );
 * }
 * ```
 *
 * A wrapper rather than a bare `int` because the alternative is a method that
 * takes an integer and would happily accept the domain's account limit.
 */
final readonly class AuditEntryId
{
    /**
     * @param positive-int $value
     */
    private function __construct(public int $value)
    {
    }

    /**
     * @throws InvalidAuditEntry when the database reported no usable id
     */
    public static function of(int $value): self
    {
        if ($value < 1) {
            throw new InvalidAuditEntry(sprintf(
                'An audit entry id has to be positive; %d came back from the write. An entry that '
                . 'cannot be referred to cannot be the parent of a batch.',
                $value,
            ));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

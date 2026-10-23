<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

/**
 * One list, one line of the changeover protocol.
 */
final readonly class ListDeliveryOutcome
{
    /**
     * @param string $reason why it was skipped or failed — a fixed phrase for
     *                       an operator, never an exception message
     * @param int    $rows   how many `virtual` rows were (or, in a dry run,
     *                       would be) changed
     */
    public function __construct(
        public string $address,
        public ListDeliveryResult $result,
        public string $reason = '',
        public int $rows = 0,
    ) {
    }

    /** `migrated`, `skipped: <reason>`, … */
    public function label(): string
    {
        return $this->reason === ''
            ? $this->result->value
            : $this->result->value . ': ' . $this->reason;
    }
}

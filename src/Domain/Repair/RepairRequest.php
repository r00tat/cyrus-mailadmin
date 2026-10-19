<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Repair;

/**
 * One inconsistency between the database and the mail store, described well
 * enough that somebody can finish the job by hand (architecture 8.2).
 *
 * The four fields are the four questions a repair needs answered, and they are
 * the ones the architecture names: **what was intended**, **which step failed**,
 * **which state was actually reached**, and **why**. A log line has none of that
 * structure, which is why `CLAUDE.md` insists this is not a log line.
 *
 * Values are plain strings rather than value objects on purpose. A repair entry
 * outlives the operation that produced it and may well describe a state no value
 * object can represent any more — a mailbox under a name that no longer matches
 * any account, for instance. Turning that into an identity in order to record it
 * would be the one place where the recording itself can fail.
 *
 * **Nothing here is a secret.** The entry names mailboxes and domains; it never
 * carries a password, a hash or a token, because A6 shows it in an interface and
 * keeps it in `mailadmin_repair_queue` (U-28).
 *
 * ## Parameters
 *
 * `$parameters` is what a retry needs and the prose fields cannot give it
 * without being parsed (A6, U-118): the stored Cyrus user name and its domain of
 * record, a folder list, a quota limit. Keys are `snake_case`, values strings.
 * An entry without parameters is still a complete description for a human; it
 * simply cannot be retried by the application.
 */
final readonly class RepairRequest
{
    /**
     * @param string $operation what was being done, as a stable key — `domain.rename`
     * @param string $step      the step that failed, as a stable key — `mailstore.rename_back`
     * @param string $subject   what it was being done to — a mailbox or a domain name
     * @param string $intended  the state the operation was aiming for
     * @param string $reached   the state that actually holds now
     * @param string $reason    an English explanation, for a human reading the queue
     * @param array<string, string> $parameters what a retry needs, never a secret
     */
    public function __construct(
        public string $operation,
        public string $step,
        public string $subject,
        public string $intended,
        public string $reached,
        public string $reason,
        public array $parameters = [],
    ) {
    }

    /**
     * One parameter, or the empty string when the entry does not carry it.
     */
    public function parameter(string $name): string
    {
        return $this->parameters[$name] ?? '';
    }

    /**
     * The six descriptive fields as a flat map — what a log line carries when
     * the table cannot be written. The parameters travel separately.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'step' => $this->step,
            'subject' => $this->subject,
            'intended' => $this->intended,
            'reached' => $this->reached,
            'reason' => $this->reason,
        ];
    }
}

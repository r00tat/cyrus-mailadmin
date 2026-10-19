<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Repair;

/**
 * Where an operation that could neither finish nor be undone puts what it left
 * behind (`CLAUDE.md`, order of operations; architecture 8.2).
 *
 * A port, declared here and implemented outside, for the same reason the mail
 * store has one (U-37): the use cases that need it are the ones where a mistake
 * costs mail, and they have to be testable without either a database or a Cyrus.
 *
 * ## Why A1 declares it and A6 fills it
 *
 * The table `mailadmin_repair_queue` belongs to A6 and to migration range
 * 0040–0049 (U-6). A1 is the first package that can produce an entry — a domain
 * rename walks `renamemailbox` over every account, and a failure part way
 * through is precisely the state the queue exists for — so A1 declares the
 * interface and A6 supplies the implementation that writes rows (U-50).
 *
 * Until A6 the wired implementation logged at `critical` (U-50). Since A6 it is
 * `Infrastructure\Persistence\RepairQueueRepository`, which writes the row on a
 * connection of its own so that the entry survives the rollback of the
 * operation that failed, and falls back to that log line only when the insert
 * itself fails (U-117). Nothing above this interface changed.
 *
 * ## Recording must not fail the caller
 *
 * An implementation swallows its own errors. By the time this is called, the
 * operation has already failed and been compensated as far as it could be; the
 * caller is on its way to reporting that. A queue that threw here would replace
 * a precise refusal with an unrelated one and lose the description of the state
 * in the process.
 */
interface RepairQueue
{
    public function record(RepairRequest $request): void;
}

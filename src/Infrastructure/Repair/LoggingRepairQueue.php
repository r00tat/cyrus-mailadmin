<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Repair;

use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The last resort of the repair queue — a `critical` log line carrying the whole
 * entry.
 *
 * Until A6 this was the wired queue (U-50). Since A6 it is the fallback of
 * {@see \Mailadmin\Infrastructure\Persistence\RepairQueueRepository}: when the
 * row cannot be written — the database is what failed in the first place, more
 * often than not — the description of the state still lands somewhere a human
 * can find it (U-117). `CLAUDE.md` is right that a log line is not a queue; it
 * is still better than nothing.
 *
 * `critical` rather than `error`: this is the one level in the application that
 * means "a mailbox and a database row disagree, and nothing will fix it on its
 * own". The message is deliberately quiet about how it happened and loud about
 * what is now true, because that is what the reader has to act on.
 */
final readonly class LoggingRepairQueue implements RepairQueue
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function record(RepairRequest $request): void
    {
        try {
            $this->logger->critical(
                'An operation could not be completed or undone. This needs manual repair.',
                [
                    ...$request->toArray(),
                    'parameters' => $request->parameters,
                    'queue' => 'log only; the entry is not in mailadmin_repair_queue',
                ],
            );
        } catch (Throwable) {
            // Recording must not fail the caller (see RepairQueue). The caller
            // is already reporting a failure; replacing it with a logging error
            // would lose both the refusal and the description of the state.
        }
    }
}

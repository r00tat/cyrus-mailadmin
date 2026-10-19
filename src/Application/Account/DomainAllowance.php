<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Infrastructure\Mailstore\ErrorCode as MailstoreErrorCode;
use Psr\Log\LoggerInterface;

/**
 * The check against `domain.domainquota` (U-48, U-81).
 *
 * The allowance is the sum of the account **limits**, not of their usage — the
 * question is how much has been promised, and a promise is broken the moment
 * the mail arrives, not when the check runs. The limits live in Cyrus, so the
 * check costs one `GETQUOTAROOT` per account; that is why it runs only where it
 * can prevent something — creating an account with a limit, or raising a limit
 * — and never on a read. Every call goes through the same mail-store instance,
 * whose pool keeps one connection per request (architecture 9.2).
 *
 * ## The rules
 *
 * - A domain without an allowance is not checked at all.
 * - In a domain **with** one, "no limit" is refused: it does not fit into any
 *   finite allowance.
 * - An existing account without a limit counts as `0`. It is legacy data the
 *   check cannot make right, and refusing every change in its domain because of
 *   it would punish the wrong account. It is logged at `notice`.
 * - An account without a mailbox counts as `0` as well; the reconciliation
 *   (A6) is where it shows.
 * - Lowering is never checked. The caller decides what "raising" means.
 *
 * Concurrency: two simultaneous raises in one domain can both pass. The
 * allowance is a planning figure for the operator, not a hard limit Cyrus
 * enforces, and the lock that serialises account creation (U-81) would have to
 * be held across a round trip per account to close the gap.
 */
final readonly class DomainAllowance
{
    public function __construct(
        private AccountStore $store,
        private Mailstore $mailstore,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function requireRoomFor(Domain $domain, MailboxIdentity $target, Quota $requested): void
    {
        $allowance = $domain->domainQuota->limitKibibytes();

        if ($allowance === null) {
            return;
        }

        $name = $domain->name->toString();
        $limit = $requested->limitKibibytes();

        if ($limit === null) {
            throw AccountOperationFailed::quotaExceedsDomain($name, null, 0, $allowance);
        }

        $allocated = $this->allocatedBesides($domain, $target);

        if ($allocated + $limit > $allowance) {
            throw AccountOperationFailed::quotaExceedsDomain($name, $limit, $allocated, $allowance);
        }
    }

    /**
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function allocatedBesides(Domain $domain, MailboxIdentity $target): int
    {
        $sum = 0;

        foreach ($this->store->usernamesIn($domain->name) as $stored) {
            try {
                $identity = MailboxIdentity::fromStoredUsername($stored, $domain->name);
            } catch (DomainError) {
                continue;
            }

            if ($identity->equals($target)) {
                continue;
            }

            try {
                $limit = $this->mailstore->getQuota($identity)->limitKibibytes();
            } catch (MailstoreFailure $failure) {
                if ($failure->errorCode() === MailstoreErrorCode::MAILBOX_NOT_FOUND) {
                    continue;
                }

                throw AccountOperationFailed::fromMailstore($failure, $identity->imapMailbox());
            }

            if ($limit === null) {
                $this->logger->notice('An account without a quota limit counts as 0 against the domain allowance.', [
                    'domain' => $domain->name->toString(),
                    'account' => $stored,
                ]);

                continue;
            }

            $sum += $limit;
        }

        return $sum;
    }
}

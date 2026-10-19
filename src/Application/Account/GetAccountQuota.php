<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Domain\Mailstore\Quota;

/**
 * `GET /accounts/{account}/quota` — one `GETQUOTAROOT`, nothing from the
 * database but the row that says which mailbox to ask about.
 */
final readonly class GetAccountQuota
{
    public function __construct(
        private AccountLocator $locator,
        private Mailstore $mailstore,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, string $id): Quota
    {
        $identity = $this->locator->forRead($actor, $id)->requireIdentity();

        try {
            return $this->mailstore->getQuota($identity);
        } catch (MailstoreFailure $failure) {
            throw AccountOperationFailed::fromMailstore($failure, $identity->imapMailbox());
        }
    }
}

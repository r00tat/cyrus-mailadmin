<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Policy\DomainPolicy;
use Psr\Log\LoggerInterface;

/**
 * `DELETE /domains/{domain}`.
 *
 * **Refused while the domain still has accounts** (U-49). webcyradm cascaded: a
 * single confirmation removed the domain, every account in it and every mailbox
 * behind those accounts. That is a lot of mail behind one dialog, and it is a
 * decision nobody can take back.
 *
 * So the rule here is the same one A2 will apply to an account: the thing that
 * holds mail is deleted deliberately, one at a time, by someone who went looking
 * for it. A domain with accounts answers `409` and points at the accounts.
 *
 * An **empty** domain has nothing in Cyrus — a domain has no mailbox of its own,
 * only its accounts do — so this operation touches the database and nothing
 * else. There is no ordering question to get wrong (architecture 8.1), which is
 * the other reason not to cascade: cascading would put one here.
 *
 * ## Nor while it has addresses, and its assignments go with it (U-95)
 *
 * A domain without accounts can still have rows in `virtual` — aliases, forwards,
 * the catchall `@domain`. They are not keyed to the `domain` row, so deleting the
 * row leaves them behind, and creating the name again brings them back to life
 * with whatever destinations they had. They are refused like accounts
 * (`409 domain.still_has_addresses`); removing them is A3's business.
 *
 * The `domainadmin` rows that assign the domain are removed in the same step.
 * Left behind, they would hand a re-created domain of the same name to its old
 * domain master without anybody deciding so. A domain master whose last domain
 * this was can then no longer sign in (`AdminDirectory::findActor()`), which is
 * the honest consequence and is visible in A4 as an administrator without
 * domains.
 *
 * ## Under the namespace lock (U-97)
 *
 * Both checks run once as a courtesy and again under the lock that account
 * creation takes for its database step. Without the second run, an account
 * created between the check and the `DELETE` would be left in a domain that no
 * longer exists.
 *
 * `If-Match` is required for the same reason as on `PATCH`: the account count
 * that made the deletion look safe has to be the count the client actually saw.
 * A domain that had none when the page was drawn and has one now must not be
 * removed on the strength of the old picture.
 *
 * ## What is audited
 *
 * The deletion, inside its transaction, with the representation the domain had
 * as `before` (U-61, U-63), and one child entry per removed assignment —
 * object type `admin`, pointing at the deletion through `part_of`. The `409`
 * for a domain that still has accounts or addresses is
 * **not** an audit entry — a precondition the client can read off the resource
 * is not a refusal of the actor, and A4 treats its own `409`s the same way. It
 * stays an application-log line at `notice`.
 */
final readonly class DeleteDomain
{
    public function __construct(
        private DomainStore $store,
        private DomainDefaults $defaults,
        private AuditWriter $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, DomainName $name, ?string $ifMatch): void
    {
        DomainPolicy::requireDelete($actor, $name);

        $current = $this->store->find($name) ?? throw DomainOperationFailed::notFound($name);

        DomainPrecondition::require($current, $this->defaults, $ifMatch);

        // The courtesy check: the ordinary refusal takes no lock.
        $this->requireNothingLeft($actor, $current);

        $before = DomainView::full($current, $this->defaults);

        $this->store->withNamespaceLock(function () use ($actor, $name, $before): void {
            // Again under the lock (U-97): an account creation in this domain
            // takes the same lock for its database step, so whatever it wrote
            // is visible here, and whatever it has not written yet waits.
            $this->requireNothingLeft(
                $actor,
                $this->store->find($name) ?? throw DomainOperationFailed::notFound($name),
            );

            $this->store->atomically(function () use ($actor, $name, $before): void {
                $admins = $this->store->delete($name);

                $operation = $this->audit->record(AuditEntry::removal(
                    $actor,
                    'domain.delete',
                    'domain',
                    $name->toString(),
                    before: $before,
                    domain: $name,
                ));

                // One child per assignment that went with the domain (U-95),
                // the way a rename names every account it moved (U-76).
                foreach ($admins as $admin) {
                    $this->audit->record(AuditEntry::removal(
                        $actor,
                        'domain.delete',
                        'admin',
                        $admin,
                        before: ['domains' => [$name->toString()]],
                        domain: $name,
                    )->partOf($operation));
                }
            });
        });
    }

    /**
     * No accounts (U-49), and no rows in `virtual` (U-95).
     *
     * @throws DomainOperationFailed
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    private function requireNothingLeft(ActorContext $actor, DomainSummary $current): void
    {
        $name = $current->name();

        if (!$current->isEmpty()) {
            $this->logger->notice('A domain deletion was refused: the domain still has accounts.', [
                'admin' => $actor->username,
                'domain' => $name->toString(),
                'account_count' => $current->accountCount,
            ]);

            throw DomainOperationFailed::notEmpty($name, $current->accountCount);
        }

        $addresses = $this->store->addressCount($name);

        if ($addresses > 0) {
            $this->logger->notice('A domain deletion was refused: the domain still has addresses.', [
                'admin' => $actor->username,
                'domain' => $name->toString(),
                'address_count' => $addresses,
            ]);

            throw DomainOperationFailed::stillHasAddresses($name, $addresses);
        }
    }
}

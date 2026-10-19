<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Password\PersonalTerms;
use Mailadmin\Domain\Policy\AdminPolicy;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use SensitiveParameter;

/**
 * `POST /admins`.
 *
 * ## The order of operations
 *
 *  1. **Permission**, before anything is read or computed (U-44).
 *  2. **Role and scope**, before the name is looked up — a contradiction there
 *     is the client's mistake and costs nothing to report.
 *  3. **The name**, against `adminuser`. Byte comparison: the column is
 *     `latin1_bin`, so `Admin` and `admin` are two accounts and refusing the
 *     second because of the first would be this application inventing a rule
 *     the database does not have.
 *  4. **The password**, hashed *outside* the transaction. Argon2id is meant to
 *     take time; doing it with the tables locked would hold them for exactly as
 *     long as it is configured to cost.
 *  5. **The write**, in one transaction over `adminuser` and `domainadmin`.
 *
 * ## There is no mailbox
 *
 * `adminuser` and `accountuser` are separate worlds (E-7). Creating an
 * administrator touches no mail store, which is why the ordering rule in
 * `CLAUDE.md` — mailbox first, database second — has nothing to say here.
 *
 * ## The audit entry is written inside the transaction
 *
 * U-63: the `mailadmin_audit` row and the `adminuser` row commit together, so a
 * creation that rolls back leaves no entry claiming it happened. That the legacy
 * tables may be MyISAM and take part in no transaction at all is **O-11**, and
 * writing the entry outside would not improve it — it would only make the
 * ordering wrong on the installations where transactions do work.
 *
 * ## The check-then-insert window
 *
 * Between step 3 and step 5 another superuser could create the same name.
 * `adminuser.username` is the primary key, so the second write fails at the
 * database rather than producing a duplicate; the check exists to turn the usual
 * case into `409` with a code a client can translate, not to be the only guard.
 */
final readonly class CreateAdmin
{
    public function __construct(
        private AdminStore $store,
        private PasswordPolicy $passwords,
        private PasswordHasher $hasher,
        private AuditWriter $audit,
    ) {
    }

    /**
     * @param list<string> $domains
     *
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Password\InvalidPassword
     * @throws AdminOperationFailed
     */
    public function handle(
        ActorContext $actor,
        string $username,
        #[SensitiveParameter] string $password,
        AdminRole $role,
        array $domains,
    ): AdminAccount {
        AdminPolicy::requireWrite($actor);

        $assignment = AdminAssignment::validated($this->store, $username, $role, $domains);

        if ($this->store->exists($username)) {
            throw AdminOperationFailed::usernameTaken($username);
        }

        $hash = $this->hasher->hash(
            $this->passwords->accept(
                $password,
                PasswordSubject::AdminUser,
                PersonalTerms::forAdmin($username),
            ),
        );

        /** @var AdminAccount $created */
        $created = $this->store->atomically(function () use ($actor, $username, $hash, $assignment): AdminAccount {
            $this->store->create($username, $hash, $assignment->role, $assignment->domains);

            $account = $this->store->find($username) ?? throw AdminOperationFailed::notFound($username);

            // Inside the transaction (U-63): the row and the entry commit
            // together, so a rolled-back creation leaves no record claiming it
            // happened.
            $this->audit->record(AuditEntry::creation(
                $actor,
                'admin.create',
                'admin',
                $username,
                after: $account->representation(),
            ));

            return $account;
        });

        return $created;
    }
}

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
 * `PATCH /admins/{username}` — role, domain assignment and password.
 *
 * ## `null` means "not sent", and it is not the same as empty
 *
 * Each of the three parameters is nullable, and `null` means the request did not
 * mention it. That distinction carries weight for `$domains`: `null` leaves the
 * assignment alone, `[]` is an attempt to remove every domain and is refused for
 * a domain master. Collapsing the two would make a request that changes only the
 * role silently wipe the scope.
 *
 * ## The last superuser
 *
 * Demoting the last superuser leaves an installation with nobody who may create
 * a domain or an administrator — recoverable only by editing the database by
 * hand. So it is counted, in the database, at the moment of the change:
 * `countSuperusers()`. Deriving it from the actor would be wrong twice over —
 * the actor may be demoting somebody else, and the count changes without this
 * session hearing about it.
 *
 * **Demoting oneself is allowed** as long as one is not the last. It is a real
 * intention — handing the role over — and it is not a way to lock anybody out:
 * the role is read from the database on every request (architecture 5.3), so the
 * effect is immediate and visible, and the account keeps the domains it was
 * given in the same call. Refusing it would need a rule of its own for no gain
 * the last-superuser check does not already provide.
 *
 * ## The precondition is checked here, not in the handler
 *
 * `If-Match` is optional (architecture 7.1) and compared against
 * {@see AdminEtag}, which hashes the same representation the response carries
 * (U-19). It is the use case that refuses a stale write, because a check that
 * lives in the transport is a check the next caller can skip.
 *
 * ## Rewriting the assignment normalises it
 *
 * When role or domains are sent, `domainadmin` is rewritten from scratch — one
 * row per domain for a domain master, the single wildcard row for a superuser.
 * An installation that carried the wildcard for a *domain master* (U-43 honours
 * such a row, and `all_domains` reports it) therefore loses it here. That is the
 * intended reading: the client saw the flag, sent a list, and a list is what it
 * gets. When neither field is sent, nothing is rewritten and the oddity survives
 * untouched.
 */
final readonly class UpdateAdmin
{
    public function __construct(
        private AdminStore $store,
        private PasswordPolicy $passwords,
        private PasswordHasher $hasher,
        private AuditWriter $audit,
    ) {
    }

    /**
     * @param list<string>|null $domains `null` leaves the assignment as it is
     * @param string|null       $ifMatch the `If-Match` header, or `null` when
     *                                   none was sent
     *
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Password\InvalidPassword
     * @throws \Mailadmin\Infrastructure\Security\PasswordHashingFailed
     * @throws AdminOperationFailed
     */
    public function handle(
        ActorContext $actor,
        string $username,
        ?AdminRole $role = null,
        ?array $domains = null,
        #[SensitiveParameter] ?string $password = null,
        ?string $ifMatch = null,
    ): AdminAccount {
        AdminPolicy::requireWrite($actor);

        $current = $this->store->find($username) ?? throw AdminOperationFailed::notFound($username);

        if (!AdminEtag::allows($ifMatch, $current)) {
            throw AdminOperationFailed::preconditionFailed($username);
        }

        $before = $current->representation();

        $assignment = $role === null && $domains === null
            ? null
            : AdminAssignment::validated(
                $this->store,
                $username,
                $role ?? $current->role,
                $domains ?? $current->domains,
            );

        if ($assignment !== null) {
            $this->assertNotTheLastSuperuser($current, $assignment->role);
        }

        // Outside the transaction: Argon2id is deliberately expensive, and the
        // tables should not be held for the length of it.
        $hash = $password === null ? null : $this->hasher->hash(
            $this->passwords->accept(
                $password,
                PasswordSubject::AdminUser,
                PersonalTerms::forAdmin($username),
            ),
        );

        /** @var AdminAccount $updated */
        $updated = $this->store->atomically(
            function () use ($actor, $username, $assignment, $hash, $before): AdminAccount {
                if ($assignment !== null) {
                    $this->store->replaceAssignment($username, $assignment->role, $assignment->domains);
                }

                if ($hash !== null) {
                    $this->store->replacePasswordHash($username, $hash);
                }

                $account = $this->store->find($username) ?? throw AdminOperationFailed::notFound($username);

                // Inside the transaction (U-63). `before` and `after` are the
                // API representation, which carries the password *format* and
                // never the hash — so a password reset shows up as a change
                // without the trail learning the secret.
                $this->audit->record(AuditEntry::change(
                    $actor,
                    'admin.update',
                    'admin',
                    $username,
                    before: $before,
                    after: $account->representation(),
                ));

                return $account;
            },
        );

        return $updated;
    }

    /**
     * @throws AdminOperationFailed
     */
    private function assertNotTheLastSuperuser(AdminAccount $current, AdminRole $target): void
    {
        if (!$current->isSuperuser() || $target->isSuperuser()) {
            return;
        }

        if ($this->store->countSuperusers() <= 1) {
            throw AdminOperationFailed::lastSuperuser($current->username);
        }
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Password\PersonalTerms;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use SensitiveParameter;

/**
 * `PUT /accounts/{account}/password`.
 *
 * bcrypt through S1's hasher, because `PasswordSubject::AccountUser` says so —
 * the one format `password_hash()` produces that `crypt(3)`, and with it
 * `pam_mysql`, can verify (CLAUDE.md). A legacy hash is replaced by this and by
 * nothing else; there is no forced migration (E-13).
 *
 * The audit entry records the change of **format**, which is metadata (S4's
 * redactor keeps `password_format`), and nothing about the password.
 *
 * **Known gap (U-86, O-11):** on the MyISAM legacy tables a failing audit entry
 * does not undo the `UPDATE`. The new hash then stands without its record and
 * the client sees an error; repeating the request sets the same password again
 * and is audited. The old hash is not read back to set it back: the store hands
 * out no hashes, and that line is worth more than closing this gap, which A1
 * and A4 share for their single-table changes.
 */
final readonly class SetAccountPassword
{
    public const string OPERATION = 'account.password.set';

    public function __construct(
        private AccountStore $store,
        private AccountLocator $locator,
        private PasswordPolicy $passwords,
        private PasswordHasher $hasher,
        private AuditWriter $audit,
    ) {
    }

    /**
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws AccountOperationFailed
     * @throws \Mailadmin\Domain\Password\InvalidPassword
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, string $id, #[SensitiveParameter] string $password): AccountRecord
    {
        $current = $this->locator->forPasswordChange($actor, $id);

        $hash = $this->hasher->hash($this->passwords->accept(
            $password,
            PasswordSubject::AccountUser,
            PersonalTerms::forMailbox($current->requireIdentity()),
        ));

        $changed = $current->withPasswordFormat($this->hasher->detectFormat($hash));

        $this->store->atomically(function () use ($actor, $current, $changed, $hash): void {
            $this->store->updatePasswordHash($current->storedUsername, $hash);

            $this->audit->record(AuditEntry::change(
                $actor,
                self::OPERATION,
                'account',
                $current->storedUsername,
                before: ['password_format' => $current->passwordFormat->value],
                after: ['password_format' => $changed->passwordFormat->value],
                domain: $current->domain,
            ));
        });

        return $changed;
    }
}

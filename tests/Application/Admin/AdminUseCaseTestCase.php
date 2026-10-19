<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Admin;

use Mailadmin\Application\Admin\ChangeOwnPassword;
use Mailadmin\Application\Admin\CreateAdmin;
use Mailadmin\Application\Admin\DeleteAdmin;
use Mailadmin\Application\Admin\GetAdmin;
use Mailadmin\Application\Admin\ListAdmins;
use Mailadmin\Application\Admin\UpdateAdmin;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Password\InMemoryCommonPasswordList;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The wiring A4's use-case tests share.
 *
 * Nothing is mocked. The password policy, the hasher and the audit trail are the
 * real classes; only the three stores are in-memory, and each of those is the
 * double that ships beside its port. A test that asserted against a mock would
 * be asserting that the code calls what the test expects, which is not the same
 * as asserting that the rule holds.
 *
 * The Argon2id parameters are at the cheap end on purpose: these tests are about
 * the rules, and `PasswordHasherTest` already pins the real cost down. A
 * default-cost hash in every one of two dozen cases makes a suite people stop
 * running.
 */
abstract class AdminUseCaseTestCase extends TestCase
{
    protected const string PASSWORD = 'correct-horse-battery-staple';
    protected const string NEW_PASSWORD = 'another-perfectly-fine-secret';

    protected FrozenClock $clock;
    protected InMemoryAdminStore $store;
    protected InMemoryAuditTrail $audit;
    protected InMemoryRefreshTokenStore $refreshTokens;
    protected PasswordHasher $hasher;
    protected PasswordPolicy $passwords;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->store = new InMemoryAdminStore();
        $this->audit = new InMemoryAuditTrail(clock: $this->clock);
        $this->refreshTokens = new InMemoryRefreshTokenStore();
        $this->hasher = new PasswordHasher(PasswordHashingOptions::of(
            bcryptCost: 4,
            argon2MemoryKib: 8192,
            argon2TimeCost: 1,
            argon2Threads: 1,
        ));
        $this->passwords = new PasswordPolicy(new InMemoryCommonPasswordList(['password', '123456']));
    }

    protected function createAdmin(): CreateAdmin
    {
        return new CreateAdmin($this->store, $this->passwords, $this->hasher, $this->audit);
    }

    protected function updateAdmin(): UpdateAdmin
    {
        return new UpdateAdmin($this->store, $this->passwords, $this->hasher, $this->audit);
    }

    protected function deleteAdmin(): DeleteAdmin
    {
        return new DeleteAdmin(
            $this->store,
            $this->refreshTokens,
            $this->audit,
            $this->clock,
            new NullLogger(),
        );
    }

    protected function changeOwnPassword(): ChangeOwnPassword
    {
        return new ChangeOwnPassword(
            $this->store,
            $this->passwords,
            $this->hasher,
            $this->refreshTokens,
            $this->audit,
            $this->clock,
            new NullLogger(),
        );
    }

    protected function listAdmins(): ListAdmins
    {
        return new ListAdmins($this->store);
    }

    protected function getAdmin(): GetAdmin
    {
        return new GetAdmin($this->store);
    }

    /**
     * @param list<string> $domains
     */
    protected function givenAdmin(
        string $username,
        AdminRole $role = AdminRole::Superuser,
        array $domains = [],
        ?string $password = null,
        ?string $storedHash = null,
    ): void {
        $this->store->given(
            $username,
            $storedHash ?? $this->hasher->hash(
                Password::alreadyInUse($password ?? self::PASSWORD, PasswordSubject::AdminUser),
            ),
            $role,
            $domains,
        );
    }

    protected function superuser(string $username = 'root'): ActorContext
    {
        return ActorContext::superuser($username);
    }

    protected function domainMaster(string $username = 'master', string $domain = 'example.test'): ActorContext
    {
        return ActorContext::domainMaster($username, DomainName::fromStoredValue($domain));
    }
}

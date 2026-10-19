<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Admin;

use Mailadmin\Application\Admin\AdminErrorCode;
use Mailadmin\Application\Admin\AdminEtag;
use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\UpdateAdmin;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Domain\Policy\AccessDenied;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `PATCH /admins/{username}`.
 *
 * The cases that matter are the ones where being wrong makes an installation
 * unmanageable or silently wipes something: the last superuser, and the
 * difference between "not sent" and "empty".
 */
#[CoversClass(UpdateAdmin::class)]
#[CoversClass(AdminEtag::class)]
final class UpdateAdminTest extends AdminUseCaseTestCase
{
    public function testAnUnknownAdministratorIsFourOhFour(): void
    {
        try {
            $this->updateAdmin()->handle($this->superuser(), 'nobody', AdminRole::Superuser);
            self::fail('An unknown administrator was updated.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::NOT_FOUND, $failure->errorCode);
            self::assertSame(404, $failure->status);
        }
    }

    public function testTheLastSuperuserCannotBeDemoted(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);
        $this->store->withDomain('example.test');

        try {
            $this->updateAdmin()->handle(
                $this->superuser('root'),
                'root',
                AdminRole::DomainMaster,
                ['example.test'],
            );
            self::fail('The last superuser was demoted.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::LAST_SUPERUSER, $failure->errorCode);
            self::assertSame(409, $failure->status);
        }

        self::assertSame(AdminRole::Superuser, $this->store->find('root')?->role);
    }

    /**
     * Handing the role over is a real intention, and the last-superuser rule is
     * what keeps it from locking anybody out.
     */
    public function testASuperuserMayDemoteThemselvesWhileAnotherOneRemains(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('second');
        $this->store->withDomain('example.test');

        $updated = $this->updateAdmin()->handle(
            $this->superuser('root'),
            'root',
            AdminRole::DomainMaster,
            ['example.test'],
        );

        self::assertSame(AdminRole::DomainMaster, $updated->role);
        self::assertSame(['example.test'], $updated->domains);
        self::assertFalse($updated->allDomains);
    }

    public function testAnAbsentDomainListLeavesTheAssignmentAlone(): void
    {
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);
        $this->store->withDomain('example.test');

        $updated = $this->updateAdmin()->handle(
            $this->superuser(),
            'master',
            password: self::NEW_PASSWORD,
        );

        self::assertSame(['example.test'], $updated->domains);
    }

    /**
     * The other half of the same rule: `[]` is an intention, not an omission,
     * and for a domain master it is refused.
     */
    public function testAnEmptyDomainListIsRefusedForADomainMaster(): void
    {
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);
        $this->store->withDomain('example.test');

        try {
            $this->updateAdmin()->handle($this->superuser(), 'master', domains: []);
            self::fail('A domain master was left with no domains.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::ROLE_SCOPE_MISMATCH, $failure->errorCode);
        }

        self::assertSame(['example.test'], $this->store->find('master')?->domains);
    }

    public function testPromotingADomainMasterReplacesTheAssignmentWithTheWildcard(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);
        $this->store->withDomain('example.test');

        $updated = $this->updateAdmin()->handle($this->superuser('root'), 'master', AdminRole::Superuser, []);

        self::assertTrue($updated->allDomains);
        self::assertSame([], $updated->domains);
    }

    public function testSettingAPasswordRewritesTheHashAsArgonTwoId(): void
    {
        // A legacy DES-crypt hash, as an existing installation may still hold.
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test'], storedHash: 'abJnggxhB/yWI');
        $this->store->withDomain('example.test');

        $updated = $this->updateAdmin()->handle($this->superuser(), 'master', password: self::NEW_PASSWORD);

        self::assertSame(PasswordFormat::Argon2id, $updated->passwordFormat);
        self::assertTrue($this->hasher->verify(self::NEW_PASSWORD, (string) $this->store->storedHash('master')));
    }

    public function testARefusedPasswordLeavesEverythingAsItWas(): void
    {
        $this->givenAdmin('master', AdminRole::DomainMaster, ['other.test']);
        $this->store->withDomain('other.test', 'example.test');
        $before = $this->store->storedHash('master');

        try {
            $this->updateAdmin()->handle(
                $this->superuser(),
                'master',
                domains: ['example.test'],
                password: 'tooshort',
            );
            self::fail('A short password was accepted.');
        } catch (InvalidPassword) {
            // expected
        }

        // The hash is checked before anything is written, so the assignment must
        // not have moved either.
        self::assertSame($before, $this->store->storedHash('master'));
        self::assertSame(['other.test'], $this->store->find('master')?->domains);
    }

    public function testAStaleIfMatchIsRefusedWithFourTwelve(): void
    {
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);
        $this->store->withDomain('example.test', 'other.test');

        try {
            $this->updateAdmin()->handle(
                $this->superuser(),
                'master',
                domains: ['other.test'],
                ifMatch: 'W/"0000000000000000000000000000000000000000000000000000000000000000"',
            );
            self::fail('A stale If-Match was accepted.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::PRECONDITION_FAILED, $failure->errorCode);
            self::assertSame(412, $failure->status);
        }

        self::assertSame(['example.test'], $this->store->find('master')?->domains);
    }

    public function testTheCurrentEtagIsAccepted(): void
    {
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);
        $this->store->withDomain('example.test', 'other.test');

        $current = $this->store->find('master');
        self::assertNotNull($current);

        $updated = $this->updateAdmin()->handle(
            $this->superuser(),
            'master',
            domains: ['other.test'],
            ifMatch: AdminEtag::of($current),
        );

        self::assertSame(['other.test'], $updated->domains);
    }

    public function testADomainMasterMayNotChangeAdministrators(): void
    {
        $this->givenAdmin('other', AdminRole::DomainMaster, ['example.test']);

        $this->expectException(AccessDenied::class);

        $this->updateAdmin()->handle($this->domainMaster(), 'other', AdminRole::Superuser, []);
    }

    public function testTheAuditEntryCarriesBothStatesAndNoHash(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);
        $this->store->withDomain('example.test', 'other.test');

        $this->updateAdmin()->handle(
            $this->superuser('root'),
            'master',
            domains: ['other.test'],
            password: self::NEW_PASSWORD,
        );

        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('admin.update', $entries[0]->action);
        self::assertSame(['example.test'], $entries[0]->before['domains'] ?? null);
        self::assertSame(['other.test'], $entries[0]->after['domains'] ?? null);

        $document = json_encode($entries[0]->toRepresentation(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::NEW_PASSWORD, $document);
        self::assertStringNotContainsString('$argon2id$', $document);
    }
}

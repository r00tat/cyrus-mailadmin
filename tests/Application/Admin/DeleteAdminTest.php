<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Admin;

use Mailadmin\Application\Admin\AdminErrorCode;
use Mailadmin\Application\Admin\AdminEtag;
use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\DeleteAdmin;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Infrastructure\Security\RefreshToken;
use Mailadmin\Infrastructure\Security\RefreshTokenRecord;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `DELETE /admins/{username}`.
 */
#[CoversClass(DeleteAdmin::class)]
final class DeleteAdminTest extends AdminUseCaseTestCase
{
    public function testAnOrdinaryDeletionRemovesTheAccount(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);

        $this->deleteAdmin()->handle($this->superuser('root'), 'master');

        self::assertNull($this->store->find('master'));
    }

    public function testTheLastSuperuserCannotBeDeleted(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);

        try {
            // Deleted by somebody who is not the target, so that the
            // self-deletion rule cannot be what refuses it.
            $this->deleteAdmin()->handle($this->superuser('master'), 'root');
            self::fail('The last superuser was deleted.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::LAST_SUPERUSER, $failure->errorCode);
            self::assertSame(409, $failure->status);
        }

        self::assertNotNull($this->store->find('root'));
    }

    public function testASuperuserCannotDeleteThemselves(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('second');

        try {
            $this->deleteAdmin()->handle($this->superuser('root'), 'root');
            self::fail('An administrator deleted their own account.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::SELF_DELETION, $failure->errorCode);
            self::assertSame(409, $failure->status);
        }

        self::assertNotNull($this->store->find('root'));
    }

    /**
     * The comparison is byte-wise, because `adminuser.username` is `latin1_bin`
     * and `Root` really is a different account.
     */
    public function testTheSelfCheckDoesNotFoldCase(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('Root');
        $this->givenAdmin('third');

        $this->deleteAdmin()->handle($this->superuser('root'), 'Root');

        self::assertNull($this->store->find('Root'));
        self::assertNotNull($this->store->find('root'));
    }

    public function testAnUnknownAdministratorIsFourOhFour(): void
    {
        $this->givenAdmin('root');

        try {
            $this->deleteAdmin()->handle($this->superuser('root'), 'nobody');
            self::fail('An unknown administrator was deleted.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::NOT_FOUND, $failure->errorCode);
        }
    }

    public function testAStaleIfMatchIsRefusedBeforeAnythingIsRemoved(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);

        try {
            $this->deleteAdmin()->handle($this->superuser('root'), 'master', 'W/"not-the-current-tag"');
            self::fail('A stale If-Match was accepted.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::PRECONDITION_FAILED, $failure->errorCode);
        }

        self::assertNotNull($this->store->find('master'));
    }

    public function testTheCurrentEtagIsAccepted(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);

        $target = $this->store->find('master');
        self::assertNotNull($target);

        $this->deleteAdmin()->handle($this->superuser('root'), 'master', AdminEtag::of($target));

        self::assertNull($this->store->find('master'));
    }

    /**
     * The access token dies on its own — role and scope are read per request —
     * but the refresh families would otherwise sit in the table naming somebody
     * who no longer exists.
     */
    public function testTheRefreshFamiliesOfTheDeletedAdministratorAreRevoked(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);
        $this->givenRefreshToken('master');
        $this->givenRefreshToken('root');

        $this->deleteAdmin()->handle($this->superuser('root'), 'master');

        foreach ($this->refreshTokens->all() as $record) {
            self::assertSame($record->subject === 'master', $record->isRevoked(), $record->subject);
        }
    }

    public function testADomainMasterMayNotDeleteAdministrators(): void
    {
        $this->givenAdmin('other', AdminRole::DomainMaster, ['example.test']);

        $this->expectException(AccessDenied::class);

        $this->deleteAdmin()->handle($this->domainMaster(), 'other');
    }

    public function testTheAuditEntryRecordsTheStateThatWasRemoved(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);

        $this->deleteAdmin()->handle($this->superuser('root'), 'master');

        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('admin.delete', $entries[0]->action);
        self::assertSame('master', $entries[0]->objectId);
        self::assertSame(['example.test'], $entries[0]->before['domains'] ?? null);
        self::assertNull($entries[0]->after);
    }

    private function givenRefreshToken(string $subject): void
    {
        $token = RefreshToken::generate();

        $this->refreshTokens->store(new RefreshTokenRecord(
            tokenHash: RefreshToken::hashOf($token->value),
            family: 'family-' . $subject,
            subject: $subject,
            issuedAt: $this->clock->now(),
            expiresAt: $this->clock->now()->modify('+12 hours'),
        ));
    }
}

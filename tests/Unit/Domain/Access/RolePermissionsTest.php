<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Access;

use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Domain\Model\AdminRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RolePermissions::class)]
final class RolePermissionsTest extends TestCase
{
    public function testASuperuserHoldsEveryPermissionThereIs(): void
    {
        $superuser = RolePermissions::of(AdminRole::Superuser);

        foreach (Permission::grantable() as $permission) {
            self::assertTrue(
                $superuser->includes($permission),
                sprintf('A superuser does not hold "%s". A new permission has to reach them.', $permission->value),
            );
        }
    }

    public function testADomainMasterHoldsStrictlyLess(): void
    {
        $domainMaster = RolePermissions::of(AdminRole::DomainMaster);

        self::assertLessThan(
            RolePermissions::of(AdminRole::Superuser)->count(),
            $domainMaster->count(),
            'The two roles would be indistinguishable.',
        );
    }

    /**
     * @return iterable<string, array{Permission}>
     */
    public static function domainMasterHolds(): iterable
    {
        // The daily work, and exactly what webcyradm let them do: accounts,
        // addresses, their own password, their own session.
        yield 'reads their domains' => [Permission::DomainRead];
        yield 'reads accounts' => [Permission::AccountRead];
        yield 'creates and edits accounts' => [Permission::AccountWrite];
        yield 'deletes accounts' => [Permission::AccountDelete];
        yield 'sets account passwords' => [Permission::AccountPasswordWrite];
        yield 'reads addresses' => [Permission::AddressRead];
        yield 'writes addresses' => [Permission::AddressWrite];
        yield 'deletes addresses' => [Permission::AddressDelete];
        // Mailing lists in their domains (spec 4.3, "Rechte").
        yield 'reads mailing lists' => [Permission::ListRead];
        yield 'writes mailing lists' => [Permission::ListWrite];
        yield 'deletes mailing lists' => [Permission::ListDelete];
        yield 'reads their session' => [Permission::SessionRead];
        yield 'ends their session' => [Permission::SessionWrite];
        yield 'changes their own password' => [Permission::ProfilePasswordWrite];
        yield 'reads their own preferences' => [Permission::ProfilePreferencesRead];
        yield 'changes their own preferences' => [Permission::ProfilePreferencesWrite];
    }

    #[DataProvider('domainMasterHolds')]
    public function testADomainMaster(Permission $permission): void
    {
        self::assertTrue(RolePermissions::of(AdminRole::DomainMaster)->includes($permission));
    }

    /**
     * @return iterable<string, array{Permission}>
     */
    public static function domainMasterDoesNotHold(): iterable
    {
        // Each of these began with `if ($_SESSION['admintype'] != 0)` in
        // webcyradm's validate.inc.php, or has no legacy counterpart at all.
        yield 'creates or renames a domain' => [Permission::DomainWrite];
        yield 'deletes a domain' => [Permission::DomainDelete];
        yield 'reads administrators' => [Permission::AdminRead];
        yield 'creates administrators' => [Permission::AdminWrite];
        yield 'deletes administrators' => [Permission::AdminDelete];
        yield 'reads the audit trail' => [Permission::AuditRead];
        yield 'reads the reconciliation' => [Permission::MaintenanceRead];
        yield 'runs a bulk password reset' => [Permission::MaintenanceWrite];
    }

    #[DataProvider('domainMasterDoesNotHold')]
    public function testADomainMasterDoesNot(Permission $permission): void
    {
        self::assertFalse(RolePermissions::of(AdminRole::DomainMaster)->includes($permission));
    }

    public function testTheTwoProvidersTogetherCoverEveryPermission(): void
    {
        // Without this, adding a permission and forgetting the provider would
        // leave the domain master's rights untested for it — and untested here
        // means "whatever the enum's declaration order happened to give".
        $named = [];

        foreach ([...self::domainMasterHolds(), ...self::domainMasterDoesNotHold()] as $case) {
            $named[] = $case[0]->value;
        }

        sort($named);

        $all = array_map(static fn (Permission $p): string => $p->value, Permission::grantable());
        sort($all);

        self::assertSame($all, $named, 'A permission is not covered by either provider.');
    }

    public function testTheMappingIsPurelyByRoleAndCarriesNoScope(): void
    {
        // Two domain masters with different domains hold the same permissions.
        // What differs is which objects those permissions reach, and that is the
        // policies' business — see the note on the `permissions` member of
        // `GET /auth/me`.
        self::assertEquals(
            RolePermissions::of(AdminRole::DomainMaster)->toStrings(),
            RolePermissions::domainMaster()->toStrings(),
        );
    }
}

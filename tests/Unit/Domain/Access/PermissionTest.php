<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Access;

use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Access\PermissionSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Permission::class)]
#[CoversClass(PermissionSet::class)]
final class PermissionTest extends TestCase
{
    public function testEveryPermissionFollowsTheFormTheContractRequires(): void
    {
        foreach (Permission::grantable() as $permission) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/',
                $permission->value,
                sprintf('"%s" is not <resource>.<verb> (openapi/README.md, 5).', $permission->value),
            );
        }
    }

    public function testTheValuesAreUnique(): void
    {
        $values = array_map(static fn (Permission $p): string => $p->value, Permission::cases());

        self::assertSame($values, array_values(array_unique($values)));
    }

    public function testEveryFamilyBelongsToAPackageThatOwnsIt(): void
    {
        // Not a style rule. A permission in a family nobody owns is a permission
        // no role will be given, which surfaces as a 403 that nobody can explain.
        $owned = ['session', 'profile', 'domain', 'account', 'address', 'admin', 'list', 'audit', 'maintenance'];

        foreach (Permission::grantable() as $permission) {
            self::assertContains($permission->resource(), $owned, sprintf(
                '"%s" invents the family "%s".',
                $permission->value,
                $permission->resource(),
            ));
        }
    }

    public function testPublicIsTheOnlyValueThatIsNotAPermission(): void
    {
        self::assertTrue(Permission::PublicAccess->isPublic());
        self::assertFalse(Permission::PublicAccess->isGrantable());
        self::assertNotContains(Permission::PublicAccess, Permission::grantable());

        foreach (Permission::grantable() as $permission) {
            self::assertFalse($permission->isPublic(), $permission->value . ' claims to be public.');
        }
    }

    public function testPublicIsSpelledTheSameWayTheAuthenticationMiddlewareSpellsIt(): void
    {
        // S2 wrote the literal before this enum existed and its own tests hang
        // off the constant. Two spellings of "no token required" would mean an
        // operation that authentication waves through and authorisation refuses.
        self::assertSame(
            \Mailadmin\Http\Middleware\AuthenticationMiddleware::PUBLIC_PERMISSION,
            Permission::PublicAccess->value,
        );
    }

    public function testASetRefusesToContainThePublicMarker(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/absence of a permission/');

        PermissionSet::of(Permission::PublicAccess);
    }

    public function testASetAnswersMembership(): void
    {
        $set = PermissionSet::of(Permission::DomainRead, Permission::AccountWrite);

        self::assertTrue($set->includes(Permission::DomainRead));
        self::assertTrue($set->includes(Permission::AccountWrite));
        self::assertFalse($set->includes(Permission::DomainWrite));
        self::assertSame(2, $set->count());
    }

    public function testASetIgnoresDuplicates(): void
    {
        $set = PermissionSet::of(Permission::DomainRead, Permission::DomainRead);

        self::assertSame(1, $set->count());
    }

    public function testAnEmptySetGrantsNothing(): void
    {
        $set = PermissionSet::none();

        self::assertTrue($set->isEmpty());
        self::assertSame([], $set->toStrings());

        foreach (Permission::grantable() as $permission) {
            self::assertFalse($set->includes($permission));
        }
    }

    public function testEverythingIsEveryGrantablePermission(): void
    {
        $set = PermissionSet::everything();

        self::assertSame(count(Permission::grantable()), $set->count());

        foreach (Permission::grantable() as $permission) {
            self::assertTrue($set->includes($permission), $permission->value . ' is missing.');
        }
    }

    public function testTheStringsAreSortedSoThatTwoIdenticalSetsSerialiseIdentically(): void
    {
        $one = PermissionSet::of(Permission::SessionWrite, Permission::AccountRead, Permission::DomainRead);
        $other = PermissionSet::of(Permission::DomainRead, Permission::SessionWrite, Permission::AccountRead);

        self::assertSame(['account.read', 'domain.read', 'session.write'], $one->toStrings());
        self::assertSame($one->toStrings(), $other->toStrings());
    }

    public function testWithAddsWithoutMutating(): void
    {
        $set = PermissionSet::of(Permission::DomainRead);
        $wider = $set->with(Permission::DomainWrite);

        self::assertFalse($set->includes(Permission::DomainWrite));
        self::assertTrue($wider->includes(Permission::DomainWrite));
        self::assertTrue($wider->includes(Permission::DomainRead));
    }

    public function testTheResourceIsThePartBeforeTheFirstDot(): void
    {
        self::assertSame('account', Permission::AccountPasswordWrite->resource());
        self::assertSame('session', Permission::SessionRead->resource());
    }
}

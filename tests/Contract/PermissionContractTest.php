<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Domain\Model\AdminRole;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The contract and S3's permission model have to describe the same API.
 *
 * They are two artefacts — a YAML document and a PHP enum — and nothing but
 * these tests keeps them one truth. The build already refuses a contract that
 * names an unknown permission; what is checked here is the rest: that the
 * declarations are coherent, and that an operation which can be refused says so
 * in its responses.
 */
#[CoversNothing]
final class PermissionContractTest extends ContractTestCase
{
    /**
     * Operations every role may perform that still answer `403` — not for a
     * missing permission but for a domain outside the actor's scope, where
     * there is no existing object a `404` could hide. Creating a mailing list
     * in a foreign domain is the one case (spec 4.3, "Rechte").
     */
    private const array FORBIDDEN_BY_SCOPE = ['POST /lists'];

    public function testEveryDeclaredPermissionIsOneTheCodeKnows(): void
    {
        foreach (self::operations() as $where => $operation) {
            $declared = $operation['x-permission'] ?? null;

            self::assertIsString($declared, $where . ' has no x-permission.');
            self::assertNotNull(
                Permission::tryFrom($declared),
                sprintf('%s declares "%s", which is not a case of %s.', $where, $declared, Permission::class),
            );
        }
    }

    public function testThePublicMarkerAndTheEmptySecurityRequirementAlwaysTravelTogether(): void
    {
        // ContractConventionsTest checks one direction — an operation that
        // overrides `security` must be marked public. This is the other: an
        // operation marked public must have overridden it. Without both, an
        // operation could carry `x-permission: public` and still demand a token,
        // which is how a sign-in endpoint becomes unreachable.
        foreach (self::operations() as $where => $operation) {
            if (($operation['x-permission'] ?? null) !== Permission::PublicAccess->value) {
                continue;
            }

            self::assertSame([], $operation['security'] ?? null, $where . ' is public but still requires a token.');
        }
    }

    public function testAnOperationThatSomeRoleCannotPerformDeclaresAForbiddenResponse(): void
    {
        // Response validation (U-9) rejects an undeclared status in development
        // and test. Without this rule the first `403` an A-package produces
        // would be reported as a contract violation rather than as the refusal
        // it is — and the fix would look like "stop sending 403".
        $missing = [];

        foreach (self::operations() as $where => $operation) {
            $permission = Permission::tryFrom((string) ($operation['x-permission'] ?? ''));

            if ($permission === null || !$permission->isGrantable() || self::everyRoleHolds($permission)) {
                continue;
            }

            /** @var array<array-key, mixed> $responses */
            $responses = $operation['responses'] ?? [];

            if (!array_key_exists(403, $responses)) {
                $missing[] = sprintf('%s (needs %s)', $where, $permission->value);
            }
        }

        // Collected rather than asserted per operation, so that the test still
        // asserts something while the contract holds only `/auth/*` — every one
        // of which both roles may call. Otherwise it would be a green test that
        // checks nothing, and would stay green when A1 arrives.
        self::assertSame([], $missing, 'These operations can be refused with 403 but do not declare it.');
    }

    public function testAnOperationEveryRoleCanPerformDoesNotPretendItCanBeForbidden(): void
    {
        // The converse, and it is not pedantry: a declared response that cannot
        // occur is a response a frontend writes handling for and never exercises.
        foreach (self::operations() as $where => $operation) {
            $permission = Permission::tryFrom((string) ($operation['x-permission'] ?? ''));

            if ($permission === null || !self::everyRoleHolds($permission)
                || in_array($where, self::FORBIDDEN_BY_SCOPE, true)) {
                continue;
            }

            /** @var array<array-key, mixed> $responses */
            $responses = $operation['responses'] ?? [];

            self::assertArrayNotHasKey(403, $responses, sprintf(
                '%s declares a 403 it cannot produce: every role holds "%s".',
                $where,
                $permission->value,
            ));
        }
    }

    public function testAPublicOperationNeverDeclaresARefusalItCannotProduce(): void
    {
        foreach (self::operations() as $where => $operation) {
            if (($operation['x-permission'] ?? null) !== Permission::PublicAccess->value) {
                continue;
            }

            /** @var array<array-key, mixed> $responses */
            $responses = $operation['responses'] ?? [];

            self::assertArrayNotHasKey(403, $responses, $where . ' is public and cannot be forbidden.');
        }
    }

    public function testTheCurrentSessionCarriesThePermissionsArchitectureSevenTwoPromises(): void
    {
        /** @var array<string, mixed> $schema */
        $schema = self::contract()['components']['schemas']['CurrentSession'];

        self::assertContains('permissions', $schema['required']);
        self::assertSame('array', $schema['properties']['permissions']['type']);
        self::assertSame('string', $schema['properties']['permissions']['items']['type']);
    }

    public function testEveryPermissionAnOperationDeclaresIsHeldBySomebody(): void
    {
        // A permission no role carries makes the operation unreachable for
        // everyone — a `403` with no way to fix it but a code change.
        foreach (self::operations() as $where => $operation) {
            $permission = Permission::tryFrom((string) ($operation['x-permission'] ?? ''));

            if ($permission === null || !$permission->isGrantable()) {
                continue;
            }

            self::assertTrue(
                self::anyRoleHolds($permission),
                sprintf('%s needs "%s", which no role holds.', $where, $permission->value),
            );
        }
    }

    private static function everyRoleHolds(Permission $permission): bool
    {
        foreach (AdminRole::cases() as $role) {
            if (!RolePermissions::of($role)->includes($permission)) {
                return false;
            }
        }

        return true;
    }

    private static function anyRoleHolds(Permission $permission): bool
    {
        foreach (AdminRole::cases() as $role) {
            if (RolePermissions::of($role)->includes($permission)) {
                return true;
            }
        }

        return false;
    }
}

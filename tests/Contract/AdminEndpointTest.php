<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use Mailadmin\Application\Admin\AdminAccount;
use Mailadmin\Application\Admin\AdminErrorCode;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Infrastructure\Persistence\AdminRepository;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionClass;
use ReflectionMethod;

/**
 * A4's endpoints against the contract they are compiled from.
 *
 * These are the places where two artefacts have to say the same thing and only a
 * test keeps them one truth: the `sort` enumeration and the repository's
 * allowlist, the error codes and the `ErrorCode` enum, the `Admin` schema and
 * the array a handler actually sends.
 */
#[CoversNothing]
final class AdminEndpointTest extends ContractTestCase
{
    private const string COLLECTION = '/admins';
    private const string ITEM = '/admins/{username}';
    private const string OWN_PASSWORD = '/admins/me/password';
    private const string OWN_PREFERENCES = '/admins/me/preferences';

    public function testTheEightOperationsExistWithThePermissionsUFortyThreeAndUNinetyAssign(): void
    {
        $expected = [
            'GET ' . self::COLLECTION => Permission::AdminRead,
            'POST ' . self::COLLECTION => Permission::AdminWrite,
            'GET ' . self::ITEM => Permission::AdminRead,
            'PATCH ' . self::ITEM => Permission::AdminWrite,
            'DELETE ' . self::ITEM => Permission::AdminDelete,
            'PUT ' . self::OWN_PASSWORD => Permission::ProfilePasswordWrite,
            // W2 uses A4's tag for the caller's own preferences (U-90).
            'GET ' . self::OWN_PREFERENCES => Permission::ProfilePreferencesRead,
            'PUT ' . self::OWN_PREFERENCES => Permission::ProfilePreferencesWrite,
        ];

        $operations = self::operations();

        foreach ($expected as $where => $permission) {
            self::assertArrayHasKey($where, $operations, $where . ' is missing from the contract.');
            self::assertSame($permission->value, $operations[$where]['x-permission'] ?? null, $where);
        }
    }

    /**
     * The five administrative operations are superuser-only, so each has to
     * declare the refusal it can actually produce; the own-password one is held
     * by both roles and must not pretend it can be forbidden. `PermissionContractTest`
     * checks the rule for the whole contract — this pins it for A4, where getting
     * it wrong would be a `403` that response validation reports as a contract
     * violation.
     */
    public function testOnlyTheSuperuserOnlyOperationsDeclareAForbiddenResponse(): void
    {
        foreach (self::adminOperations() as $where => $operation) {
            $permission = Permission::tryFrom((string) ($operation['x-permission'] ?? ''));
            self::assertNotNull($permission, $where);

            $everyRoleHolds = true;
            foreach (AdminRole::cases() as $role) {
                $everyRoleHolds = $everyRoleHolds && RolePermissions::of($role)->includes($permission);
            }

            /** @var array<array-key, mixed> $responses */
            $responses = $operation['responses'] ?? [];

            self::assertSame(
                !$everyRoleHolds,
                array_key_exists(403, $responses),
                $where . ' declares a 403 it cannot produce, or omits one it can.',
            );
        }
    }

    public function testTheOwnPasswordOperationIsTheOneADomainMasterReaches(): void
    {
        // The whole reason `profile.password.write` is a permission of its own
        // (U-43). If this ever needed `admin.write`, a domain master would have
        // to ask a superuser to set a password the superuser then knows.
        self::assertTrue(
            RolePermissions::of(AdminRole::DomainMaster)->includes(Permission::ProfilePasswordWrite),
        );
        self::assertFalse(RolePermissions::of(AdminRole::DomainMaster)->includes(Permission::AdminWrite));
    }

    public function testEveryErrorCodeAFourRaisesIsInTheContractEnumeration(): void
    {
        $enum = self::errorCodes();

        foreach (AdminErrorCode::all() as $code) {
            self::assertContains($code, $enum, sprintf('AdminErrorCode "%s" is not in the contract.', $code));
        }
    }

    /**
     * The `sort` enumeration and {@see AdminRepository::sortable()} are two
     * lists of the same thing. A key in the contract that the repository does not
     * offer is a `500` on a request the schema accepts.
     */
    public function testTheSortEnumerationMatchesTheRepositoryAllowlist(): void
    {
        /** @var array<string, mixed> $parameter */
        $parameter = self::contract()['components']['parameters']['AdminSort'];

        /** @var list<string> $declared */
        $declared = $parameter['schema']['enum'];

        $allowlist = self::sortAllowlist();

        sort($declared);
        $offered = $allowlist->keys();
        sort($offered);

        self::assertSame($offered, $declared);
        self::assertSame($allowlist->defaultKey(), $parameter['schema']['default']);
    }

    /**
     * The `Admin` schema and {@see AdminAccount::representation()} — the array
     * that is serialised into the body and hashed into the `ETag`.
     */
    public function testTheAdminSchemaDescribesExactlyWhatIsSent(): void
    {
        /** @var array<string, mixed> $schema */
        $schema = self::contract()['components']['schemas']['Admin'];

        $sent = array_keys((new AdminAccount(
            username: 'someone',
            role: AdminRole::DomainMaster,
            allDomains: false,
            domains: ['example.test'],
            orphanedDomains: [],
            passwordFormat: PasswordFormat::Argon2id,
        ))->representation());

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];

        $declared = array_keys($properties);
        sort($declared);
        sort($sent);

        self::assertSame($sent, $declared);

        // Every one of them is required: an `Admin` with a missing member would
        // be an `Admin` a client has to guard against for no reason.
        /** @var list<string> $required */
        $required = $schema['required'];
        sort($required);

        self::assertSame($sent, $required);
    }

    public function testThePasswordFormatEnumerationCoversEveryFormatTheDetectorCanReturn(): void
    {
        /** @var list<string> $declared */
        $declared = self::contract()['components']['schemas']['AdminPasswordFormat']['enum'];

        $known = array_map(static fn (PasswordFormat $f): string => $f->value, PasswordFormat::cases());

        sort($declared);
        sort($known);

        self::assertSame($known, $declared);
    }

    /**
     * The wildcard is a scope, not a domain name (U-43). A schema that accepted
     * `*` would make „domain master for every domain" reachable without the
     * role.
     */
    public function testTheDomainPatternRejectsTheWildcard(): void
    {
        /** @var string $pattern */
        $pattern = self::contract()['components']['schemas']['AdminDomainName']['pattern'];

        self::assertSame(0, preg_match('/' . $pattern . '/', AdminRole::ALL_DOMAINS));
        self::assertSame(1, preg_match('/' . $pattern . '/', 'example.test'));
    }

    public function testThePasswordMinimumMatchesTheRuleForAdministrators(): void
    {
        // U-21: twelve for `adminuser`, ten for `accountuser`. The contract
        // states the stricter one so that a client can say so before the round
        // trip; the policy enforces it regardless.
        self::assertSame(
            \Mailadmin\Domain\Password\PasswordSubject::AdminUser->minimumLength(),
            self::contract()['components']['schemas']['AdminPassword']['minLength'],
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function adminOperations(): array
    {
        $mine = [];

        foreach (self::operations() as $where => $operation) {
            if (in_array('admins', (array) ($operation['tags'] ?? []), true)) {
                $mine[$where] = $operation;
            }
        }

        self::assertCount(8, $mine);

        return $mine;
    }

    private static function sortAllowlist(): SortAllowlist
    {
        $repository = (new ReflectionClass(AdminRepository::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($repository, 'sortable');

        /** @var SortAllowlist $allowlist */
        $allowlist = $method->invoke($repository);

        return $allowlist;
    }

    /**
     * @return list<string>
     */
    private static function errorCodes(): array
    {
        /** @var list<string> $enum */
        $enum = self::contract()['components']['schemas']['ErrorCode']['enum'];

        return $enum;
    }
}

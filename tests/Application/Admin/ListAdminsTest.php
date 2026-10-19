<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Admin;

use Mailadmin\Application\Admin\AdminAccount;
use Mailadmin\Application\Admin\AdminErrorCode;
use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\GetAdmin;
use Mailadmin\Application\Admin\ListAdmins;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `GET /admins` and `GET /admins/{username}`.
 *
 * The interesting part of the listing is not the paging — F3 owns that — but
 * what it shows: U-22 wants a legacy state visible rather than filtered away.
 */
#[CoversClass(ListAdmins::class)]
#[CoversClass(GetAdmin::class)]
#[CoversClass(AdminAccount::class)]
final class ListAdminsTest extends AdminUseCaseTestCase
{
    public function testTheListingIsSortedByNameAndPaged(): void
    {
        $this->givenAdmin('carla');
        $this->givenAdmin('anna');
        $this->givenAdmin('bruno');

        $page = $this->listAdmins()->handle($this->superuser(), new ListCriteria(page: 1, perPage: 2));

        self::assertSame(3, $page->total);
        self::assertSame(['anna', 'bruno'], array_map(static fn (AdminAccount $a): string => $a->username, $page->items));
    }

    public function testTheDirectionIsHonoured(): void
    {
        $this->givenAdmin('anna');
        $this->givenAdmin('bruno');

        $page = $this->listAdmins()->handle(
            $this->superuser(),
            new ListCriteria(sort: 'username', order: SortDirection::Descending),
        );

        self::assertSame(['bruno', 'anna'], array_map(static fn (AdminAccount $a): string => $a->username, $page->items));
    }

    public function testTheRoleFilterUsesTheLegacyTypeValue(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);

        $page = $this->listAdmins()->handle(
            $this->superuser(),
            new ListCriteria(filters: ['role' => AdminRole::DomainMaster->toLegacyType()]),
        );

        self::assertSame(1, $page->total);
        self::assertSame('master', $page->items[0]->username);
    }

    /**
     * U-22: an assignment to a domain that no longer has a row in `domain` is
     * shown and marked. The alternative — dropping it — makes the stale data
     * invisible and therefore permanent.
     */
    public function testAnAssignmentToAMissingDomainIsShownAndMarked(): void
    {
        $this->store->withDomain('example.test');
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test', 'gone.test']);

        $admin = $this->getAdmin()->handle($this->superuser(), 'master');

        self::assertSame(['example.test', 'gone.test'], $admin->domains);
        self::assertSame(['gone.test'], $admin->orphanedDomains);
    }

    /**
     * U-22 again, for the password: an administrator still on a legacy hash is
     * visible as such until they next sign in (E-13).
     */
    public function testALegacyHashIsReportedAsSuch(): void
    {
        $this->givenAdmin('legacy', storedHash: 'abJnggxhB/yWI');

        $admin = $this->getAdmin()->handle($this->superuser(), 'legacy');

        self::assertSame(PasswordFormat::DesCrypt, $admin->passwordFormat);
        self::assertTrue($admin->passwordFormat->isLegacy());
    }

    /**
     * The representation is what the `ETag` hashes and what the audit trail
     * records, so it must never contain the hash itself.
     */
    public function testTheRepresentationCarriesTheFormatAndNotTheHash(): void
    {
        $this->givenAdmin('root');

        $representation = $this->getAdmin()->handle($this->superuser(), 'root')->representation();
        $document = json_encode($representation, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('$argon2id$', $document);
        self::assertSame('argon2id', $representation['password_format']);
        self::assertFalse($representation['password_legacy']);
    }

    public function testAnUnknownNameIsFourOhFour(): void
    {
        try {
            $this->getAdmin()->handle($this->superuser(), 'nobody');
            self::fail('An unknown administrator was returned.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::NOT_FOUND, $failure->errorCode);
            self::assertSame(404, $failure->status);
        }
    }

    public function testADomainMasterMayNotListAdministrators(): void
    {
        $this->expectException(AccessDenied::class);

        $this->listAdmins()->handle($this->domainMaster(), new ListCriteria());
    }

    public function testADomainMasterMayNotReadOneEither(): void
    {
        $this->givenAdmin('root');

        $this->expectException(AccessDenied::class);

        $this->getAdmin()->handle($this->domainMaster(), 'root');
    }

    /**
     * A read changes nothing, so it writes nothing — S4's package document is
     * explicit that reads are not audited.
     */
    public function testReadingIsNotAudited(): void
    {
        $this->givenAdmin('root');

        $this->listAdmins()->handle($this->superuser(), new ListCriteria());
        $this->getAdmin()->handle($this->superuser(), 'root');

        self::assertTrue($this->audit->isEmpty());
    }
}

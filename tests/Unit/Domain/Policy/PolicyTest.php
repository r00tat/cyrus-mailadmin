<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\AccountPolicy;
use Mailadmin\Domain\Policy\AddressPolicy;
use Mailadmin\Domain\Policy\AdminPolicy;
use Mailadmin\Domain\Policy\DomainPolicy;
use Mailadmin\Domain\Policy\Guard;
use Mailadmin\Domain\Policy\ListPolicy;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The five policies and the guard they share.
 *
 * The assertion that matters most in this file is not "may or may not" — it is
 * **which** refusal comes back. A `403` where a `404` belongs confirms that
 * somebody else's object exists, and that is the enumeration hole S3 exists to
 * close.
 */
#[CoversClass(Guard::class)]
#[CoversClass(DomainPolicy::class)]
#[CoversClass(AccountPolicy::class)]
#[CoversClass(AddressPolicy::class)]
#[CoversClass(AdminPolicy::class)]
#[CoversClass(ListPolicy::class)]
#[CoversClass(AccessDenied::class)]
#[CoversClass(ResourceNotVisible::class)]
final class PolicyTest extends TestCase
{
    private const string OWN = 'own.example';
    private const string FOREIGN = 'foreign.example';

    // ------------------------------------------------------------------
    // The split that everything else rests on
    // ------------------------------------------------------------------

    public function testAForeignDomainIsNotFoundRatherThanForbidden(): void
    {
        $this->expectException(ResourceNotVisible::class);

        DomainPolicy::requireRead($this->domainMaster(), self::domain(self::FOREIGN));
    }

    public function testTheRefusalForAForeignObjectCarriesTheSameCodeAsAnAbsentOne(): void
    {
        try {
            AccountPolicy::requireRead($this->domainMaster(), $this->account(self::FOREIGN));
            self::fail('A foreign account was visible.');
        } catch (ResourceNotVisible $hidden) {
            self::assertSame(DomainErrorCode::REQUEST_NOT_FOUND, $hidden->code());
        }
    }

    public function testAnOperationTheRoleCannotPerformIsForbidden(): void
    {
        try {
            DomainPolicy::requireDelete($this->domainMaster(), self::domain(self::OWN));
            self::fail('A domain master deleted a domain.');
        } catch (AccessDenied $denied) {
            self::assertSame(DomainErrorCode::REQUEST_FORBIDDEN, $denied->code());
            self::assertSame(Permission::DomainDelete, $denied->permission);
        }
    }

    public function testThePermissionIsCheckedBeforeVisibility(): void
    {
        // Both would fail. It has to be the 403: answering 404 here would say
        // "no such domain" for one that exists, and 404 for one that does not —
        // the leak in the other direction.
        $this->expectException(AccessDenied::class);

        DomainPolicy::requireDelete($this->domainMaster(), self::domain(self::FOREIGN));
    }

    public function testNeitherRefusalPutsTheActorsPasswordOrTokenAnywhere(): void
    {
        // The domain model never sees a credential, so the messages quote their
        // input freely. This asserts the premise still holds.
        $denied = null;

        try {
            DomainPolicy::requireDelete($this->domainMaster(), self::domain(self::OWN));
        } catch (AccessDenied $error) {
            $denied = $error;
        }

        self::assertInstanceOf(AccessDenied::class, $denied);
        self::assertStringContainsString('domain.delete', $denied->getMessage());
        self::assertSame(['permission' => 'domain.delete'], $denied->context());
    }

    // ------------------------------------------------------------------
    // Domains
    // ------------------------------------------------------------------

    public function testASuperuserReadsWritesAndDeletesAnyDomain(): void
    {
        $actor = ActorContext::superuser('root');

        DomainPolicy::requireRead($actor, self::domain(self::FOREIGN));
        DomainPolicy::requireCreate($actor);
        DomainPolicy::requireWrite($actor, self::domain(self::FOREIGN));
        DomainPolicy::requireDelete($actor, self::domain(self::FOREIGN));

        $this->addToAssertionCount(4);
    }

    public function testADomainMasterReadsTheirOwnDomain(): void
    {
        DomainPolicy::requireRead($this->domainMaster(), self::domain(self::OWN));

        $this->addToAssertionCount(1);
    }

    public function testADomainMasterCannotCreateADomain(): void
    {
        $this->expectException(AccessDenied::class);

        DomainPolicy::requireCreate($this->domainMaster());
    }

    public function testADomainMasterCannotRenameTheirOwnDomain(): void
    {
        // A rename walks every mailbox in Cyrus before the database is touched.
        $this->expectException(AccessDenied::class);

        DomainPolicy::requireWrite($this->domainMaster(), self::domain(self::OWN));
    }

    public function testListingDomainsChecksThePermissionAndNotAnyOneDomain(): void
    {
        // The narrowing is the query's job — a per-row check would answer 404
        // for a domain master's own list.
        DomainPolicy::requireList($this->domainMaster());

        $this->addToAssertionCount(1);
    }

    public function testThePredicatesAgreeWithTheGuards(): void
    {
        $actor = $this->domainMaster();

        self::assertTrue(DomainPolicy::mayRead($actor, self::domain(self::OWN)));
        self::assertFalse(DomainPolicy::mayRead($actor, self::domain(self::FOREIGN)));
        self::assertFalse(DomainPolicy::mayWrite($actor, self::domain(self::OWN)));
        self::assertFalse(DomainPolicy::mayDelete($actor, self::domain(self::OWN)));
    }

    // ------------------------------------------------------------------
    // Accounts
    // ------------------------------------------------------------------

    public function testADomainMasterDoesEverythingToAnAccountInTheirDomain(): void
    {
        $actor = $this->domainMaster();
        $account = $this->account(self::OWN);

        AccountPolicy::requireRead($actor, $account);
        AccountPolicy::requireWrite($actor, $account);
        AccountPolicy::requireDelete($actor, $account);
        AccountPolicy::requirePasswordChange($actor, $account);
        AccountPolicy::requireListIn($actor, self::domain(self::OWN));
        AccountPolicy::requireCreateIn($actor, self::domain(self::OWN));

        $this->addToAssertionCount(6);
    }

    public function testEveryAccountOperationInAForeignDomainIsNotFound(): void
    {
        $actor = $this->domainMaster();
        $account = $this->account(self::FOREIGN);
        $foreign = self::domain(self::FOREIGN);

        $refused = 0;

        foreach ([
            static fn () => AccountPolicy::requireRead($actor, $account),
            static fn () => AccountPolicy::requireWrite($actor, $account),
            static fn () => AccountPolicy::requireDelete($actor, $account),
            static fn () => AccountPolicy::requirePasswordChange($actor, $account),
            static fn () => AccountPolicy::requireListIn($actor, $foreign),
            static fn () => AccountPolicy::requireCreateIn($actor, $foreign),
        ] as $attempt) {
            try {
                $attempt();
            } catch (ResourceNotVisible) {
                ++$refused;
            }
        }

        self::assertSame(6, $refused, 'An account operation reached a foreign domain.');
    }

    public function testTheDomainComesFromTheStoredIdentityAndNotFromAPathSegment(): void
    {
        // The identity carries `accountuser.domain_name`. Checking anything else
        // would be checking a claim the caller made.
        $account = $this->account(self::FOREIGN);

        self::assertSame(self::FOREIGN, $account->domain->toString());
        self::assertFalse(AccountPolicy::mayRead($this->domainMaster(), $account));
    }

    // ------------------------------------------------------------------
    // Addresses
    // ------------------------------------------------------------------

    public function testADomainMasterManagesAddressesInTheirDomain(): void
    {
        $actor = $this->domainMaster();
        $address = self::address('info', self::OWN);

        AddressPolicy::requireRead($actor, $address);
        AddressPolicy::requireWrite($actor, $address);
        AddressPolicy::requireDelete($actor, $address);
        AddressPolicy::requireCatchallWrite($actor, self::domain(self::OWN));
        AddressPolicy::requireListIn($actor, self::domain(self::OWN));

        $this->addToAssertionCount(5);
    }

    public function testAnAliasInAForeignDomainIsNotFound(): void
    {
        $this->expectException(ResourceNotVisible::class);

        AddressPolicy::requireWrite($this->domainMaster(), self::address('info', self::FOREIGN));
    }

    public function testAForwardToTheOutsideWorldIsNobodysBusiness(): void
    {
        AddressPolicy::requireDestinationVisible(
            $this->domainMaster(),
            self::address('somebody', 'elsewhere.invalid'),
            isLocalDomain: false,
        );

        $this->addToAssertionCount(1);
    }

    public function testTheOptionalDestinationRuleRefusesAForeignLocalDomain(): void
    {
        $this->expectException(ResourceNotVisible::class);

        AddressPolicy::requireDestinationVisible(
            $this->domainMaster(),
            self::address('boss', self::FOREIGN),
            isLocalDomain: true,
        );
    }

    // ------------------------------------------------------------------
    // Administrators
    // ------------------------------------------------------------------

    public function testOnlyASuperuserAdministersAdministrators(): void
    {
        $superuser = ActorContext::superuser('root');

        AdminPolicy::requireRead($superuser);
        AdminPolicy::requireWrite($superuser);
        AdminPolicy::requireDelete($superuser);

        $this->addToAssertionCount(3);
        self::assertTrue(AdminPolicy::mayManage($superuser));
    }

    public function testADomainMasterIsForbiddenNotHiddenOnEveryAdminEndpoint(): void
    {
        // No 404 here: an administrator is not in a domain, so there is nothing
        // whose existence could be revealed. The permission refuses first, and
        // before any lookup.
        $actor = $this->domainMaster();
        $refused = 0;

        foreach ([
            static fn () => AdminPolicy::requireRead($actor),
            static fn () => AdminPolicy::requireWrite($actor),
            static fn () => AdminPolicy::requireDelete($actor),
        ] as $attempt) {
            try {
                $attempt();
            } catch (AccessDenied) {
                ++$refused;
            }
        }

        self::assertSame(3, $refused);
        self::assertFalse(AdminPolicy::mayManage($actor));
    }

    public function testBothRolesChangeTheirOwnPassword(): void
    {
        AdminPolicy::requireOwnPasswordChange($this->domainMaster(), 'master');
        AdminPolicy::requireOwnPasswordChange(ActorContext::superuser('root'), 'root');

        $this->addToAssertionCount(2);
    }

    public function testTheSelfServicePasswordEndpointReachesNobodyElse(): void
    {
        try {
            AdminPolicy::requireOwnPasswordChange(ActorContext::superuser('root'), 'somebody-else');
            self::fail('A superuser changed another password through the self-service permission.');
        } catch (AccessDenied $denied) {
            self::assertSame(Permission::ProfilePasswordWrite, $denied->permission);
        }
    }

    public function testTheOwnNameIsComparedByteForByte(): void
    {
        // `adminuser.username` is latin1_bin: webcyradm treats Master and master
        // as two accounts, so folding case here would let one act as the other.
        self::assertTrue(AdminPolicy::isSelf($this->domainMaster(), 'master'));
        self::assertFalse(AdminPolicy::isSelf($this->domainMaster(), 'Master'));
    }

    // ------------------------------------------------------------------
    // Mailing lists
    // ------------------------------------------------------------------

    public function testMailingListsAreSuperuserOnlyForNow(): void
    {
        $superuser = ActorContext::superuser('root');

        ListPolicy::requireRead($superuser);
        ListPolicy::requireWrite($superuser);
        ListPolicy::requireDelete($superuser);

        $this->addToAssertionCount(3);

        $this->expectException(AccessDenied::class);
        ListPolicy::requireRead($this->domainMaster());
    }

    public function testTheScopingHookA5WouldNeedIsThereAndWorks(): void
    {
        ListPolicy::requireVisible($this->domainMaster(), self::address('team', self::OWN));

        $this->addToAssertionCount(1);

        $this->expectException(ResourceNotVisible::class);
        ListPolicy::requireVisible($this->domainMaster(), self::address('team', self::FOREIGN));
    }

    // ------------------------------------------------------------------
    // The guard itself
    // ------------------------------------------------------------------

    public function testTheWildcardRowMakesADomainMasterUnrestricted(): void
    {
        // SqlAdminDirectory honours `domainadmin.domain_name = '*'` for a domain
        // master too. The policies must agree, or the row would silently mean
        // something different depending on who asked.
        $actor = ActorContext::of(
            'wildcard',
            \Mailadmin\Domain\Model\AdminRole::DomainMaster,
            \Mailadmin\Domain\Access\DomainScope::all(),
        );

        DomainPolicy::requireRead($actor, self::domain(self::FOREIGN));
        AccountPolicy::requireRead($actor, $this->account(self::FOREIGN));

        $this->addToAssertionCount(2);
        // Unrestricted scope is not a wider role: they still cannot delete one.
        self::assertFalse(DomainPolicy::mayDelete($actor, self::domain(self::FOREIGN)));
    }

    public function testAllowsAnswersWithoutThrowing(): void
    {
        $actor = $this->domainMaster();

        self::assertTrue(Guard::allows($actor, Permission::AccountRead));
        self::assertTrue(Guard::allows($actor, Permission::AccountRead, self::domain(self::OWN)));
        self::assertFalse(Guard::allows($actor, Permission::AccountRead, self::domain(self::FOREIGN)));
        self::assertFalse(Guard::allows($actor, Permission::AdminRead));
    }

    // ------------------------------------------------------------------

    private function domainMaster(): ActorContext
    {
        return ActorContext::domainMaster('master', self::domain(self::OWN));
    }

    private function account(string $domain): MailboxIdentity
    {
        return MailboxIdentity::create(
            LocalPart::fromString('user'),
            self::domain($domain),
            NamingScheme::Dotted,
        );
    }

    private static function domain(string $name): DomainName
    {
        return DomainName::fromString($name);
    }

    private static function address(string $localPart, string $domain): EmailAddress
    {
        return new EmailAddress(LocalPart::fromString($localPart), self::domain($domain));
    }
}

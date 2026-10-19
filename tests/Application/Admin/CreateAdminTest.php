<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Admin;

use Mailadmin\Application\Admin\AdminAssignment;
use Mailadmin\Application\Admin\AdminErrorCode;
use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\CreateAdmin;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Domain\Policy\AccessDenied;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `POST /admins`.
 */
#[CoversClass(CreateAdmin::class)]
#[CoversClass(AdminAssignment::class)]
#[CoversClass(AdminOperationFailed::class)]
final class CreateAdminTest extends AdminUseCaseTestCase
{
    public function testASuperuserIsStoredWithArgonTwoIdAndNoNamedDomains(): void
    {
        $this->createAdmin()->handle(
            $this->superuser(),
            'nina',
            self::PASSWORD,
            AdminRole::Superuser,
            [],
        );

        $created = $this->store->find('nina');

        self::assertNotNull($created);
        self::assertSame(AdminRole::Superuser, $created->role);
        self::assertSame([], $created->domains);
        // The wildcard row, not an enumeration: this is how webcyradm writes
        // "every domain", and an installation running both interfaces has to see
        // the same thing in each.
        self::assertTrue($created->allDomains);
        self::assertSame(PasswordFormat::Argon2id, $created->passwordFormat);
    }

    public function testADomainMasterKeepsTheDomainsItWasGivenSortedAndDeduplicated(): void
    {
        $this->store->withDomain('b.test', 'a.test');

        $created = $this->createAdmin()->handle(
            $this->superuser(),
            'nina',
            self::PASSWORD,
            AdminRole::DomainMaster,
            ['b.test', 'a.test', 'b.test'],
        );

        self::assertSame(['a.test', 'b.test'], $created->domains);
        self::assertFalse($created->allDomains);
    }

    public function testADomainMasterWithoutDomainsIsRefused(): void
    {
        // Such an account cannot act at all: findActor() answers null and the
        // sign-in refuses it. Writing it would lock somebody out with no visible
        // cause.
        try {
            $this->createAdmin()->handle(
                $this->superuser(),
                'nina',
                self::PASSWORD,
                AdminRole::DomainMaster,
                [],
            );
            self::fail('An empty scope was accepted for a domain master.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::ROLE_SCOPE_MISMATCH, $failure->errorCode);
            self::assertSame(422, $failure->status);
        }

        self::assertNull($this->store->find('nina'));
    }

    public function testASuperuserWithNamedDomainsIsRefused(): void
    {
        $this->store->withDomain('a.test');

        try {
            $this->createAdmin()->handle(
                $this->superuser(),
                'nina',
                self::PASSWORD,
                AdminRole::Superuser,
                ['a.test'],
            );
            self::fail('Named domains were accepted for a superuser.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::ROLE_SCOPE_MISMATCH, $failure->errorCode);
        }
    }

    /**
     * The wildcard is a scope, not a name. Accepting it as a domain would make
     * "domain master for `*`" a way to become a superuser without the role.
     */
    public function testTheWildcardCannotBeAssignedAsADomain(): void
    {
        try {
            $this->createAdmin()->handle(
                $this->superuser(),
                'nina',
                self::PASSWORD,
                AdminRole::DomainMaster,
                ['*'],
            );
            self::fail('The wildcard was accepted as a domain name.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::ROLE_SCOPE_MISMATCH, $failure->errorCode);
        }
    }

    public function testADomainThatDoesNotExistIsRefused(): void
    {
        $this->store->withDomain('a.test');

        try {
            $this->createAdmin()->handle(
                $this->superuser(),
                'nina',
                self::PASSWORD,
                AdminRole::DomainMaster,
                ['a.test', 'gone.test'],
            );
            self::fail('An unknown domain was accepted.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::UNKNOWN_DOMAIN, $failure->errorCode);
            self::assertSame(422, $failure->status);
        }
    }

    public function testATakenNameIsAConflict(): void
    {
        $this->givenAdmin('nina');

        try {
            $this->createAdmin()->handle(
                $this->superuser(),
                'nina',
                self::PASSWORD,
                AdminRole::Superuser,
                [],
            );
            self::fail('A duplicate name was accepted.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::USERNAME_TAKEN, $failure->errorCode);
            self::assertSame(409, $failure->status);
        }
    }

    /**
     * `adminuser.username` is `latin1_bin`. Refusing `Nina` because `nina`
     * exists would be this application inventing a rule the database does not
     * have — and it would be the wrong rule, because webcyradm can create both.
     */
    public function testTheNameComparisonIsCaseSensitive(): void
    {
        $this->givenAdmin('nina');

        $created = $this->createAdmin()->handle(
            $this->superuser(),
            'Nina',
            self::PASSWORD,
            AdminRole::Superuser,
            [],
        );

        self::assertSame('Nina', $created->username);
        self::assertNotNull($this->store->find('nina'));
    }

    public function testAPasswordShorterThanTwelveCharactersIsRefused(): void
    {
        $this->expectException(InvalidPassword::class);

        $this->createAdmin()->handle(
            $this->superuser(),
            'nina',
            'short-one',
            AdminRole::Superuser,
            [],
        );
    }

    /**
     * U-21: the password must not contain the user's own name. Twelve characters
     * of "nina" repeated is long enough and still a bad password.
     */
    public function testAPasswordContainingTheOwnNameIsRefused(): void
    {
        $this->expectException(InvalidPassword::class);

        $this->createAdmin()->handle(
            $this->superuser(),
            'nina',
            'ninaninanina-and-more',
            AdminRole::Superuser,
            [],
        );
    }

    public function testADomainMasterMayNotCreateAdministrators(): void
    {
        $this->expectException(AccessDenied::class);

        $this->createAdmin()->handle(
            $this->domainMaster(),
            'nina',
            self::PASSWORD,
            AdminRole::Superuser,
            [],
        );
    }

    public function testTheAuditEntryRecordsTheCreationAndCarriesNoSecret(): void
    {
        $this->createAdmin()->handle(
            $this->superuser('root'),
            'nina',
            self::PASSWORD,
            AdminRole::Superuser,
            [],
        );

        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('admin.create', $entries[0]->action);
        self::assertSame('nina', $entries[0]->objectId);
        self::assertSame('root', $entries[0]->actor);
        self::assertNull($entries[0]->before);

        $document = json_encode($entries[0]->toRepresentation(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::PASSWORD, $document);
        self::assertStringNotContainsString('$argon2id$', $document);
        // The *format* stays, deliberately: E-13 and U-22 want a legacy hash
        // visible as a fact about the account.
        self::assertStringContainsString('argon2id', $document);
    }

    public function testNothingIsWrittenWhenTheAssignmentIsRefused(): void
    {
        try {
            $this->createAdmin()->handle(
                $this->superuser(),
                'nina',
                self::PASSWORD,
                AdminRole::DomainMaster,
                [],
            );
        } catch (AdminOperationFailed) {
            // expected
        }

        self::assertNull($this->store->find('nina'));
        self::assertTrue($this->audit->isEmpty());
    }
}

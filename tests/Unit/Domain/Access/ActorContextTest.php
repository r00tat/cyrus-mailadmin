<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Access;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\DomainScope;
use Mailadmin\Domain\Exception\InvalidActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorContext::class)]
#[CoversClass(DomainScope::class)]
#[CoversClass(InvalidActorContext::class)]
final class ActorContextTest extends TestCase
{
    public function testASuperuserSeesEveryDomainWithoutEnumeratingThem(): void
    {
        $actor = ActorContext::superuser('admin');

        self::assertTrue($actor->isSuperuser());
        self::assertFalse($actor->requiresDomainScope());
        self::assertTrue($actor->canSee(DomainName::fromString('anything.test')));
        self::assertSame([], $actor->visibleDomains());
        self::assertTrue($actor->scope->isUnrestricted());
        self::assertFalse($actor->scope->isEmpty());
    }

    public function testADomainMasterSeesOnlyTheirDomains(): void
    {
        $actor = ActorContext::domainMaster(
            'master',
            DomainName::fromString('example.test'),
            DomainName::fromString('second.test'),
        );

        self::assertFalse($actor->isSuperuser());
        self::assertTrue($actor->requiresDomainScope());
        self::assertTrue($actor->canSee(DomainName::fromString('EXAMPLE.test')));
        self::assertFalse($actor->canSee(DomainName::fromString('other.test')));
        self::assertSame(['example.test', 'second.test'], $actor->scope->domainNames());
        self::assertCount(2, $actor->visibleDomains());
    }

    public function testADomainMasterWithoutADomainIsAConfigurationError(): void
    {
        // An actor who may see nothing is not an empty result list.
        $this->expectException(InvalidActorContext::class);

        ActorContext::domainMaster('master');
    }

    public function testASuperuserStaysUnrestrictedEvenWithoutTheWildcardRow(): void
    {
        $actor = ActorContext::of('admin', AdminRole::Superuser, DomainScope::of());

        self::assertTrue($actor->scope->isUnrestricted());
        self::assertTrue($actor->canSee(DomainName::fromString('example.test')));
    }

    public function testADomainMasterReadFromTheDatabaseNeedsAScope(): void
    {
        $this->expectException(InvalidActorContext::class);

        ActorContext::of('master', AdminRole::DomainMaster, DomainScope::of());
    }

    public function testAnEmptyUserNameIsRefused(): void
    {
        $this->expectException(InvalidActorContext::class);

        ActorContext::superuser('   ');
    }

    public function testTheUserNameIsTrimmedAndTheRoleKept(): void
    {
        $actor = ActorContext::of(
            ' master ',
            AdminRole::DomainMaster,
            DomainScope::of(DomainName::fromString('example.test')),
        );

        self::assertSame('master', $actor->username);
        self::assertSame(AdminRole::DomainMaster, $actor->role);
    }
}

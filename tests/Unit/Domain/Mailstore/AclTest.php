<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\Mailstore;

use Mailadmin\Domain\Exception\InvalidAcl;
use Mailadmin\Domain\Mailstore\Acl;
use Mailadmin\Domain\Mailstore\AclRight;
use Mailadmin\Domain\Mailstore\AclSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Acl::class)]
#[CoversClass(AclSet::class)]
#[CoversClass(AclRight::class)]
#[CoversClass(InvalidAcl::class)]
final class AclTest extends TestCase
{
    public function testRightsAreRenderedInCanonicalOrder(): void
    {
        // Two equal permission sets must produce the same string, otherwise a
        // reordering looks like a change in the ETag and in the audit trail.
        self::assertSame('lrs', Acl::fromString('srl')->toString());
        self::assertSame('lrs', Acl::fromString('lrs')->toString());
        self::assertTrue(Acl::fromString('srl')->equals(Acl::readOnly()));
    }

    public function testDuplicatesAreRemoved(): void
    {
        self::assertSame('lr', Acl::fromString('llrr')->toString());
    }

    public function testTheTwoPresetsMatchWhatTheLegacyClientUsed(): void
    {
        self::assertSame('lrswipkxtecda', Acl::fullControl()->toString());
        self::assertSame('lrswipcda', Acl::legacyFullControl()->toString());
    }

    public function testAnUnknownRightIsRefused(): void
    {
        $this->expectException(InvalidAcl::class);

        Acl::fromString('lrz');
    }

    public function testAnEmptyAclIsAllowed(): void
    {
        // Cyrus removes an identity from the list by setting empty rights.
        self::assertTrue(Acl::fromString('')->isEmpty());
        self::assertSame('', Acl::none()->toString());
    }

    public function testInclusionAsksForEveryRight(): void
    {
        self::assertTrue(Acl::fullControl()->includes(Acl::readOnly()));
        self::assertFalse(Acl::readOnly()->includes(Acl::fullControl()));
        self::assertTrue(Acl::readOnly()->has(AclRight::Read));
        self::assertFalse(Acl::readOnly()->has(AclRight::Administer));
    }

    public function testRightsCanBeAddedAndRemoved(): void
    {
        $acl = Acl::readOnly()->with(AclRight::Administer)->without(AclRight::Seen);

        self::assertSame('lra', $acl->toString());
        self::assertCount(3, $acl->rights());
    }

    public function testASetMapsSubjectsToRights(): void
    {
        $set = AclSet::fromStrings([
            'greta.mustermann.example.test' => 'lrswipkxtecda',
            'anyone' => 'p',
        ]);

        self::assertSame(2, $set->count());
        self::assertSame(
            ['greta.mustermann.example.test', 'anyone'],
            $set->subjects(),
        );
        self::assertTrue($set->grants('anyone', AclRight::Post));
        self::assertFalse($set->grants('anyone', AclRight::Read));
        self::assertFalse($set->isWorldReadable());
        self::assertNull($set->for('nobody'));
    }

    public function testAWorldReadableMailboxIsVisible(): void
    {
        $set = AclSet::fromArray([AclSet::ANYONE => Acl::readOnly()]);

        self::assertTrue($set->isWorldReadable());
    }

    public function testASetIsImmutable(): void
    {
        $set = AclSet::empty();
        $extended = $set->with('admin', Acl::fullControl());

        self::assertTrue($set->isEmpty());
        self::assertSame(1, $extended->count());
        self::assertTrue($extended->without('admin')->isEmpty());
        self::assertSame(['admin' => 'lrswipkxtecda'], $extended->toStrings());
        self::assertArrayHasKey('admin', $extended->toArray());
    }

    public function testAnEmptySubjectIsRefused(): void
    {
        $this->expectException(InvalidAcl::class);

        AclSet::empty()->with('  ', Acl::readOnly());
    }
}

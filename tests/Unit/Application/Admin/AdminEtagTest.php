<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Application\Admin;

use Mailadmin\Application\Admin\AdminAccount;
use Mailadmin\Application\Admin\AdminEtag;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\PasswordFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The validator behind `If-Match` (U-19).
 */
#[CoversClass(AdminEtag::class)]
final class AdminEtagTest extends TestCase
{
    public function testTheTagIsWeakAndAHexDigest(): void
    {
        self::assertMatchesRegularExpression('#^W/"[0-9a-f]{64}"$#', AdminEtag::of(self::admin()));
    }

    public function testTwoIdenticalRepresentationsShareATag(): void
    {
        self::assertSame(AdminEtag::of(self::admin()), AdminEtag::of(self::admin()));
    }

    /**
     * Every member of the representation has to move the tag — a field that did
     * not would be a field two administrators could disagree about while both
     * `If-Match` headers still matched.
     */
    #[DataProvider('variations')]
    public function testEveryFieldChangesTheTag(AdminAccount $different): void
    {
        self::assertNotSame(AdminEtag::of(self::admin()), AdminEtag::of($different));
    }

    /**
     * @return iterable<string, array{AdminAccount}>
     */
    public static function variations(): iterable
    {
        yield 'username' => [self::admin(username: 'somebody')];
        yield 'role' => [self::admin(role: AdminRole::Superuser)];
        yield 'all_domains' => [self::admin(allDomains: true)];
        yield 'domains' => [self::admin(domains: ['other.test'])];
        yield 'orphaned_domains' => [self::admin(orphaned: ['example.test'])];
        yield 'password_format' => [self::admin(format: PasswordFormat::DesCrypt)];
    }

    public function testAnAbsentHeaderAllowsTheWrite(): void
    {
        // Architecture 7.1: writing methods *accept* `If-Match`. A client that
        // sends none gets last-write-wins rather than `428`.
        self::assertTrue(AdminEtag::allows(null, self::admin()));
        self::assertTrue(AdminEtag::allows('   ', self::admin()));
    }

    public function testTheCurrentTagAllowsTheWriteWithOrWithoutTheWeakPrefix(): void
    {
        $tag = AdminEtag::of(self::admin());

        self::assertTrue(AdminEtag::allows($tag, self::admin()));
        self::assertTrue(AdminEtag::allows(substr($tag, 2), self::admin()));
        self::assertTrue(AdminEtag::allows(trim(substr($tag, 2), '"'), self::admin()));
    }

    public function testAListIsAcceptedIfAnyMemberMatches(): void
    {
        $tag = AdminEtag::of(self::admin());

        self::assertTrue(AdminEtag::allows('W/"0000", ' . $tag, self::admin()));
    }

    public function testTheWildcardAllowsTheWrite(): void
    {
        self::assertTrue(AdminEtag::allows('*', self::admin()));
    }

    public function testAStaleTagDoesNot(): void
    {
        self::assertFalse(AdminEtag::allows('W/"0000"', self::admin()));
        self::assertFalse(AdminEtag::allows(AdminEtag::of(self::admin(username: 'other')), self::admin()));
    }

    /**
     * @param list<string> $domains
     * @param list<string> $orphaned
     */
    private static function admin(
        string $username = 'master',
        AdminRole $role = AdminRole::DomainMaster,
        bool $allDomains = false,
        array $domains = ['example.test'],
        array $orphaned = [],
        PasswordFormat $format = PasswordFormat::Argon2id,
    ): AdminAccount {
        return new AdminAccount($username, $role, $allDomains, $domains, $orphaned, $format);
    }
}

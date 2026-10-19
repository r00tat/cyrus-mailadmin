<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Application\Audit;

use Mailadmin\Application\Audit\AuditAction;
use Mailadmin\Application\Audit\AuditActor;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditEntryId;
use Mailadmin\Application\Audit\AuditOutcome;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\InvalidAuditEntry;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape of an entry — which is the shape every other package will build.
 *
 * The rules worth pinning down are the ones a caller could otherwise get wrong
 * without anything failing: that a creation cannot claim a previous state, that
 * an action name is checked rather than accepted, and that an unauthenticated
 * actor is recorded as such rather than as somebody.
 */
#[CoversClass(AuditEntry::class)]
#[CoversClass(AuditAction::class)]
#[CoversClass(AuditActor::class)]
#[CoversClass(AuditEntryId::class)]
#[CoversClass(AuditRequestContext::class)]
final class AuditEntryTest extends TestCase
{
    public function testACreationHasNoPreviousState(): void
    {
        $entry = AuditEntry::creation($this->superuser(), 'domain.create', 'domain', 'example.test', [
            'name' => 'example.test',
        ]);

        self::assertNull($entry->before);
        self::assertSame(['name' => 'example.test'], $entry->after);
        self::assertSame(AuditOutcome::Succeeded, $entry->outcome);
    }

    public function testARemovalHasNoSubsequentState(): void
    {
        $entry = AuditEntry::removal($this->superuser(), 'domain.delete', 'domain', 'example.test', [
            'name' => 'example.test',
        ]);

        self::assertSame(['name' => 'example.test'], $entry->before);
        self::assertNull($entry->after);
    }

    public function testARefusalRecordsNeitherStateAndSaysWhy(): void
    {
        $entry = AuditEntry::refusal(
            $this->superuser(),
            'domain.delete',
            'domain',
            'example.test',
            reason: 'the role does not hold domain.delete',
        );

        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertNull($entry->before);
        self::assertNull($entry->after);
        self::assertSame('the role does not hold domain.delete', $entry->reason);
    }

    public function testAFailureMayKeepThePreviousStateBecauseNothingReplacedIt(): void
    {
        $entry = AuditEntry::failure(
            $this->superuser(),
            'domain.rename',
            'domain',
            'example.test',
            reason: 'the mail store refused the rename',
            before: ['name' => 'example.test'],
        );

        self::assertSame(AuditOutcome::Failed, $entry->outcome);
        self::assertSame(['name' => 'example.test'], $entry->before);
        self::assertNull($entry->after);
    }

    public function testAnActorContextIsAcceptedDirectly(): void
    {
        // The ergonomic case: every use case already holds one of these as its
        // first parameter, so recording should not cost a conversion.
        $entry = AuditEntry::creation($this->superuser(), 'domain.create', 'domain');

        self::assertSame('root', $entry->actor->username);
        self::assertSame(AdminRole::Superuser, $entry->actor->role);
    }

    public function testAnUnauthenticatedActorIsRecordedWithoutARole(): void
    {
        $entry = AuditEntry::refusal(AuditActor::named('does-not-exist'), 'session.sign_in', 'session');

        self::assertSame('does-not-exist', $entry->actor->username);
        self::assertNull($entry->actor->role);
        self::assertSame('', $entry->actor->roleValue());
        self::assertFalse($entry->actor->isAuthenticated());
    }

    public function testTheSystemActorCannotBeConfusedWithAnAdministrator(): void
    {
        // `adminuser.username` cannot hold angle brackets, so no administrator
        // can ever collide with this name.
        self::assertSame('<system>', AuditActor::system()->username);
        self::assertFalse(AuditActor::system()->isAuthenticated());
    }

    public function testAnEmptyActorNameIsRefused(): void
    {
        $this->expectException(InvalidAuditEntry::class);

        AuditActor::named('   ');
    }

    #[DataProvider('malformedActions')]
    public function testAnActionThatIsNotObjectDotVerbIsRefused(string $action): void
    {
        $this->expectException(InvalidAuditEntry::class);

        AuditEntry::creation($this->superuser(), $action, 'domain');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedActions(): iterable
    {
        yield 'no verb' => ['domain'];
        yield 'upper case' => ['Domain.Create'];
        yield 'hyphen' => ['domain.create-thing'];
        yield 'trailing dot' => ['domain.'];
        yield 'leading dot' => ['.create'];
        yield 'free text' => ['deleted a domain'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 40) . '.' . str_repeat('b', 40)];
    }

    public function testASubObjectInTheActionIsAllowed(): void
    {
        // `account.password.write` is a permission, and an entry that cannot be
        // named after its permission is harder to line up with one.
        $entry = AuditEntry::change($this->superuser(), 'account.password.write', 'account', 'anna@example.test');

        self::assertSame('account.password.write', $entry->action->value);
        self::assertSame('account', $entry->action->object());
    }

    #[DataProvider('malformedObjectTypes')]
    public function testAnObjectTypeThatIsNotAnIdentifierIsRefused(string $type): void
    {
        $this->expectException(InvalidAuditEntry::class);

        AuditEntry::creation($this->superuser(), 'domain.create', $type);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedObjectTypes(): iterable
    {
        yield 'upper case' => ['Domain'];
        yield 'dotted' => ['domain.name'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 40)];
    }

    public function testADomainNameValueObjectAndAStringAreBothAccepted(): void
    {
        $fromObject = AuditEntry::creation(
            $this->superuser(),
            'account.create',
            'account',
            domain: DomainName::fromString('example.test'),
        );
        $fromString = AuditEntry::creation(
            $this->superuser(),
            'account.create',
            'account',
            domain: 'example.test',
        );

        self::assertSame('example.test', $fromObject->domain);
        self::assertSame('example.test', $fromString->domain);
    }

    public function testAnEntryWithoutADomainSaysSo(): void
    {
        $entry = AuditEntry::creation($this->superuser(), 'session.sign_in', 'session');

        self::assertNull($entry->domain);
    }

    public function testAnEntryCanBeMadePartOfAnOperation(): void
    {
        $operation = AuditEntryId::of(17);
        $entry = AuditEntry::change($this->superuser(), 'account.rename', 'account', 'anna');

        self::assertNull($entry->parent);
        self::assertSame(17, $entry->partOf($operation)->parent?->value);

        // Immutable: the original is untouched, so a loop cannot accidentally
        // attach every child to the previous child.
        self::assertNull($entry->parent);
    }

    public function testAnIdentifierLongerThanTheColumnIsCutRatherThanRefused(): void
    {
        $entry = AuditEntry::creation($this->superuser(), 'account.create', 'account', str_repeat('a', 400));

        // Losing the tail of a mailbox identity is a far smaller loss than
        // losing the entry that it was created.
        self::assertSame(320, strlen($entry->objectId));
    }

    public function testAReasonIsCollapsedToOneLine(): void
    {
        $entry = AuditEntry::failure(
            $this->superuser(),
            'domain.rename',
            'domain',
            reason: "the mail store\n refused\tthe rename",
        );

        self::assertSame('the mail store refused the rename', $entry->reason);
    }

    public function testTheRequestContextIsEmptyUntilSomethingBindsIt(): void
    {
        $context = new AuditRequestContext();

        self::assertSame('', $context->requestId());
        self::assertSame('', $context->clientIp());

        $context->bind('0123456789abcdef', '203.0.113.7');

        self::assertSame('0123456789abcdef', $context->requestId());
        self::assertSame('203.0.113.7', $context->clientIp());

        $context->clear();

        // Cleared, so that a second request in a long-running process cannot
        // inherit the first one's address.
        self::assertSame('', $context->requestId());
        self::assertSame('', $context->clientIp());
    }

    public function testTheRequestContextRefusesToCarryANewlineIntoAColumn(): void
    {
        $context = new AuditRequestContext();
        $context->bind("abc\ndef", "203.0.113.7\r\n");

        self::assertSame('abcdef', $context->requestId());
        self::assertSame('203.0.113.7', $context->clientIp());
    }

    public function testAnIdentifierHasToBePositive(): void
    {
        $this->expectException(InvalidAuditEntry::class);

        AuditEntryId::of(0);
    }

    private function superuser(): ActorContext
    {
        return ActorContext::superuser('root');
    }
}

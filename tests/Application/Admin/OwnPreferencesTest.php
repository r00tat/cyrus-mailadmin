<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Admin;

use DateTimeImmutable;
use Mailadmin\Application\Admin\AdminPreferences;
use Mailadmin\Application\Admin\GetOwnPreferences;
use Mailadmin\Application\Admin\SetOwnPreferences;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\InterfaceLocale;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminPreferenceStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * W2: reading and changing one's own interface language (U-90).
 */
#[CoversClass(GetOwnPreferences::class)]
#[CoversClass(SetOwnPreferences::class)]
#[CoversClass(AdminPreferences::class)]
#[CoversClass(InterfaceLocale::class)]
final class OwnPreferencesTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryAdminPreferenceStore $store;
    private InMemoryAuditTrail $audit;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->store = new InMemoryAdminPreferenceStore();
        $this->audit = new InMemoryAuditTrail(clock: $this->clock);
    }

    public function testNothingChosenReadsAsNull(): void
    {
        $preferences = $this->get()->handle(ActorContext::superuser('root'));

        self::assertNull($preferences->locale);
        self::assertSame(['locale' => null], $preferences->representation());
    }

    public function testADomainMasterMayChooseTheirOwnLanguage(): void
    {
        $actor = $this->domainMaster();

        $stored = $this->set()->handle($actor, new AdminPreferences(InterfaceLocale::German));

        self::assertSame(InterfaceLocale::German, $stored->locale);
        self::assertSame('de', $this->store->storedLocale('master'));
        self::assertSame(InterfaceLocale::German, $this->get()->handle($actor)->locale);
    }

    public function testTheSubjectIsAlwaysTheActor(): void
    {
        $this->set()->handle(ActorContext::superuser('root'), new AdminPreferences(InterfaceLocale::German));

        self::assertSame('de', $this->store->storedLocale('root'));
        self::assertNull($this->get()->handle($this->domainMaster())->locale);
    }

    public function testFollowingTheBrowserAgainRemovesTheRow(): void
    {
        $this->store->givenStoredLocale('root', 'de', new DateTimeImmutable('2025-01-01 00:00:00'));

        $stored = $this->set()->handle(ActorContext::superuser('root'), AdminPreferences::none());

        self::assertNull($stored->locale);
        self::assertNull($this->store->storedLocale('root'));
    }

    public function testAChangeIsAuditedWithBeforeAndAfter(): void
    {
        $this->set()->handle(ActorContext::superuser('root'), new AdminPreferences(InterfaceLocale::German));

        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('admin.preferences.change', $entries[0]->action);
        self::assertSame('admin', $entries[0]->objectType);
        self::assertSame('root', $entries[0]->objectId);
        self::assertSame(['locale' => null], $entries[0]->before);
        self::assertSame(['locale' => 'de'], $entries[0]->after);
    }

    public function testRepeatingTheStoredStateWritesNothing(): void
    {
        $earlier = new DateTimeImmutable('2025-01-01 00:00:00');
        $this->store->givenStoredLocale('root', 'de', $earlier);

        $this->set()->handle(ActorContext::superuser('root'), new AdminPreferences(InterfaceLocale::German));

        self::assertTrue($this->audit->isEmpty());
        self::assertEquals($earlier, $this->store->updatedAt('root'));
    }

    public function testAStoredValueThisBuildDoesNotOfferReadsAsNothingChosen(): void
    {
        // Written by a newer release that shipped another language, then
        // rolled back. Failing the response over it would lock the
        // administrator out of their own settings.
        $this->store->givenStoredLocale('root', 'xx', new DateTimeImmutable('2025-01-01 00:00:00'));

        self::assertNull($this->get()->handle(ActorContext::superuser('root'))->locale);
    }

    public function testReplacingAnUnknownStoredValueIsAuditedAsAChangeFromNothing(): void
    {
        $this->store->givenStoredLocale('root', 'xx', new DateTimeImmutable('2025-01-01 00:00:00'));

        $this->set()->handle(ActorContext::superuser('root'), new AdminPreferences(InterfaceLocale::German));

        self::assertSame('de', $this->store->storedLocale('root'));
        self::assertSame(1, $this->audit->count());
    }

    private function get(): GetOwnPreferences
    {
        return new GetOwnPreferences($this->store);
    }

    private function set(): SetOwnPreferences
    {
        return new SetOwnPreferences($this->store, $this->audit, $this->clock);
    }

    private function domainMaster(): ActorContext
    {
        return ActorContext::domainMaster('master', DomainName::fromStoredValue('example.test'));
    }
}

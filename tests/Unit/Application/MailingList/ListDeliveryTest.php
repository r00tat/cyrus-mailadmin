<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Application\MailingList;

use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Application\MailingList\ListDeliveryMode;
use Mailadmin\Config\Config;
use Mailadmin\Config\ConfigurationError;
use Mailadmin\Domain\Model\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListDelivery::class)]
final class ListDeliveryTest extends TestCase
{
    public function testDefaultsToTheInvalidPseudoDomainAndAliasMode(): void
    {
        $delivery = ListDelivery::fromConfig(Config::fromArray([]));

        self::assertSame('maillist.invalid', $delivery->transportDomain->toString());
        self::assertSame(ListDeliveryMode::Alias, $delivery->mode);
        self::assertTrue($delivery->changesNeedConfigurationRun());
    }

    public function testBothSettingsCanBeOverridden(): void
    {
        $delivery = ListDelivery::fromConfig(Config::fromArray([
            ListDelivery::TRANSPORT_DOMAIN => 'lists.example.test',
            ListDelivery::MODE => 'transport',
        ]));

        self::assertSame('lists.example.test', $delivery->transportDomain->toString());
        self::assertSame(ListDeliveryMode::Transport, $delivery->mode);
        self::assertFalse($delivery->changesNeedConfigurationRun());
    }

    public function testAnUnknownModeIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage(ListDelivery::MODE);

        ListDelivery::fromConfig(Config::fromArray([ListDelivery::MODE => 'pipe']));
    }

    public function testAnInvalidTransportDomainIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage(ListDelivery::TRANSPORT_DOMAIN);

        ListDelivery::fromConfig(Config::fromArray([ListDelivery::TRANSPORT_DOMAIN => 'not a domain']));
    }

    public function testListKeyReplacesTheAtSignWithADot(): void
    {
        self::assertSame('a.b.c.test', ListDelivery::listKey(EmailAddress::parse('a.b@c.test')));
    }

    public function testDestinationAppendsTheTransportDomain(): void
    {
        $delivery = ListDelivery::fromConfig(Config::fromArray([]));

        self::assertSame(
            'team.example.test@maillist.invalid',
            $delivery->destinationFor(EmailAddress::parse('team@example.test')),
        );
    }
}

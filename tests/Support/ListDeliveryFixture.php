<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Mailadmin\Application\Address\AddressReader;
use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Application\MailingList\ListDeliveryMode;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualEntryClassifier;
use Mailadmin\Infrastructure\Persistence\InMemoryMailingListRepository;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;

/**
 * The list delivery settings with the default transport domain, and an
 * {@see AddressReader} built on them — for tests whose subject is not the
 * mailing lists but that need a reader which recognises list rows.
 */
final class ListDeliveryFixture
{
    private function __construct()
    {
    }

    public static function delivery(): ListDelivery
    {
        return new ListDelivery(
            DomainName::fromString(ListDelivery::DEFAULT_TRANSPORT_DOMAIN),
            ListDeliveryMode::Transport,
        );
    }

    public static function reader(AddressStore $virtual, ?MailingListRepository $lists = null): AddressReader
    {
        return new AddressReader(
            $virtual,
            new VirtualEntryClassifier(),
            $lists ?? new InMemoryMailingListRepository(),
            self::delivery(),
        );
    }
}

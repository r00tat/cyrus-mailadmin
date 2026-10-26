<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * A mailing list repository that writes every write into a {@see CallTrace}
 * before passing it on — for tests that prove the order of writes across
 * `lists` and `virtual`.
 */
final readonly class TracingMailingListRepository implements MailingListRepository
{
    public function __construct(private MailingListRepository $inner, private CallTrace $trace)
    {
    }

    public function list(ActorContext $actor, ListCriteria $criteria): Page
    {
        return $this->inner->list($actor, $criteria);
    }

    public function find(EmailAddress $address): ?MailingList
    {
        return $this->inner->find($address);
    }

    public function existsWithKey(string $listKey): bool
    {
        return $this->inner->existsWithKey($listKey);
    }

    public function addressesInDomain(DomainName $domain): array
    {
        return $this->inner->addressesInDomain($domain);
    }

    public function add(MailingList $list): void
    {
        $this->trace->add('lists.add ' . $list->address);
        $this->inner->add($list);
    }

    public function replace(MailingList $list): void
    {
        $this->trace->add('lists.replace ' . $list->address);
        $this->inner->replace($list);
    }

    public function remove(EmailAddress $address): bool
    {
        $this->trace->add('lists.remove ' . $address->toString());

        return $this->inner->remove($address);
    }
}

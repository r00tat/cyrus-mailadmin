<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Model\DomainName;
use RuntimeException;

/**
 * The transport domain is also a row in `domain`. Postfix would then deliver
 * list mail locally, and the pipe transport would never see it — moving the
 * lists to the transport in that state would silence every one of them.
 */
final class TransportDomainIsManaged extends RuntimeException
{
    public static function domain(DomainName $domain): self
    {
        return new self(sprintf(
            'The list transport domain %s is a managed domain. Mail to it would be delivered locally, '
            . 'not to the list transport. Remove the domain or choose another MAILADMIN_LIST_TRANSPORT_DOMAIN first.',
            $domain->toString(),
        ));
    }
}

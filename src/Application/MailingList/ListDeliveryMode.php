<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

/**
 * How list mail reaches Mailman-less list delivery: through the alias file
 * that a configuration run rewrites, or through the Postfix pipe transport
 * that looks the list up in `lists` at delivery time.
 */
enum ListDeliveryMode: string
{
    case Alias = 'alias';
    case Transport = 'transport';
}

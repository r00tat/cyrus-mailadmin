<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Config\Config;
use Mailadmin\Config\ConfigurationError;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;

/**
 * Where list mail is delivered: the pseudo-domain of the Postfix pipe
 * transport, and whether the old alias-file path is still the active one.
 *
 * `virtual.dest` of a list is `listKey(address) . '@' . transportDomain`; the
 * pipe script looks the list up by that key. The default `maillist.invalid`
 * can never collide with a real domain.
 *
 * That the transport domain is not also a managed domain needs the database
 * and is therefore not checked here, but in the operations overview.
 */
final readonly class ListDelivery
{
    public const string TRANSPORT_DOMAIN = 'MAILADMIN_LIST_TRANSPORT_DOMAIN';
    public const string MODE = 'MAILADMIN_LIST_DELIVERY';
    public const string DEFAULT_TRANSPORT_DOMAIN = 'maillist.invalid';

    public function __construct(
        public DomainName $transportDomain,
        public ListDeliveryMode $mode,
    ) {
    }

    /**
     * @throws ConfigurationError if the domain is not a valid domain name or the mode is unknown
     */
    public static function fromConfig(Config $config): self
    {
        try {
            $domain = DomainName::fromString(
                trim($config->stringOrDefault(self::TRANSPORT_DOMAIN, self::DEFAULT_TRANSPORT_DOMAIN)),
            );
        } catch (InvalidAddress) {
            throw new ConfigurationError(sprintf(
                'Environment variable %s must be a valid domain name.',
                self::TRANSPORT_DOMAIN,
            ));
        }

        // `Config::enum()` insists on the variable being present; here absent means alias.
        $mode = $config->has(self::MODE)
            ? ListDeliveryMode::from($config->enum(
                self::MODE,
                array_map(static fn (ListDeliveryMode $m): string => $m->value, ListDeliveryMode::cases()),
            ))
            : ListDeliveryMode::Alias;

        return new self($domain, $mode);
    }

    /** REPLACE(address, '@', '.') — the key maillist.py looks the list up by. */
    public static function listKey(EmailAddress $address): string
    {
        return str_replace('@', '.', $address->toString());
    }

    /** listKey(address) . '@' . transportDomain */
    public function destinationFor(EmailAddress $address): string
    {
        return self::listKey($address) . '@' . $this->transportDomain->toString();
    }

    /** True while the alias file path is still active: changes wait for a configuration run (U-59). */
    public function changesNeedConfigurationRun(): bool
    {
        return $this->mode === ListDeliveryMode::Alias;
    }
}

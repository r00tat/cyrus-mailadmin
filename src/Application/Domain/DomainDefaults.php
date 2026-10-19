<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Config\Config;
use Mailadmin\Domain\Model\NamingScheme;

/**
 * The installation-wide defaults a domain falls back to.
 *
 * Today that is one value — `MAILADMIN_NAMING_SCHEME`, the middle step of the
 * three in architecture 3.3: the domain's own setting wins, then this, then
 * `dotted`. It is a class rather than a bare enum passed around because the
 * three-step resolution has to happen in one place, and because A1 is not the
 * last package that will want to read it (A2 creates the accounts this decides
 * the names of).
 *
 * **Configuration decides what new accounts get, never what existing ones are.**
 * An account's scheme is recognised from its stored user name
 * ({@see NamingScheme::detect()}); changing this value does not move a single
 * mailbox and must not appear to.
 *
 * An unreadable or unknown value is treated as "not configured" rather than as a
 * startup failure. The consequence of guessing wrong here is that a new account
 * is created under the wrong scheme, which is visible and repairable; the
 * consequence of refusing to boot is that nobody can fix anything at all.
 */
final readonly class DomainDefaults
{
    public const string NAMING_SCHEME = 'MAILADMIN_NAMING_SCHEME';

    public function __construct(public ?NamingScheme $namingScheme = null)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $configured = trim($config->stringOrDefault(self::NAMING_SCHEME, ''));

        return new self($configured === '' ? null : NamingScheme::tryFrom(strtolower($configured)));
    }

    /**
     * What a domain with `$domainSetting` actually gets — the whole of
     * architecture 3.3's resolution, in one call.
     */
    public function resolve(?NamingScheme $domainSetting): NamingScheme
    {
        return NamingScheme::resolve($domainSetting, $this->namingScheme);
    }
}

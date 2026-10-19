<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Mailadmin\Application\Domain\DomainSummary;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AccountLimit;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\NamingScheme;

/**
 * Domains, actors and names for A1's tests — invented, and reserved.
 *
 * Every name here is under `.test` or `.invalid` (RFC 2606/6761), which can
 * never resolve. E-5 treats a production value in a fixture as an escalation,
 * because fixtures are committed and committed things get published.
 */
final class DomainFixture
{
    private function __construct()
    {
    }

    public static function name(string $value = 'example.test'): DomainName
    {
        return DomainName::fromStoredValue($value);
    }

    public static function domain(
        string $name = 'example.test',
        ?int $maxAccounts = 20,
        ?int $accountQuotaKib = 20000,
        ?int $domainQuotaKib = null,
        ?NamingScheme $scheme = null,
        string $prefix = 'example.test',
        bool $freeNames = false,
        bool $freeAddress = false,
        string ...$folders,
    ): Domain {
        return new Domain(
            name: self::name($name),
            maxAccounts: $maxAccounts === null ? AccountLimit::unlimited() : AccountLimit::of($maxAccounts),
            defaultAccountQuota: $accountQuotaKib === null
                ? Quota::unlimited()
                : Quota::ofKibibytes($accountQuotaKib),
            domainQuota: $domainQuotaKib === null ? Quota::unlimited() : Quota::ofKibibytes($domainQuotaKib),
            defaultFolders: DefaultFolders::of(...$folders),
            namingScheme: $scheme,
            legacyPrefix: $prefix,
            transport: Domain::DEFAULT_TRANSPORT,
            freeNames: $freeNames,
            freeAddress: $freeAddress,
        );
    }

    public static function summary(string $name = 'example.test', int $accountCount = 0): DomainSummary
    {
        return new DomainSummary(self::domain($name), $accountCount);
    }

    public static function superuser(string $username = 'root'): ActorContext
    {
        return ActorContext::superuser($username);
    }

    public static function domainMaster(string $username = 'anna', string ...$domains): ActorContext
    {
        $names = $domains === [] ? ['example.test'] : $domains;

        return ActorContext::domainMaster(
            $username,
            ...array_map(static fn (string $domain): DomainName => self::name($domain), $names),
        );
    }
}

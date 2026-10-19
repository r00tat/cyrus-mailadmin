<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

/**
 * Local parts a domain is expected to keep reachable.
 *
 * `postmaster` and `abuse` are required by RFC 2142; the rest are the names the
 * legacy system also guarded. This class **states the fact, it does not
 * enforce anything**: whether a use case warns or refuses is A3's open point 5.
 * Keeping the list here means the two answers cannot drift apart.
 *
 * **Assumption:** the list is a constant for now. Making it configurable is a
 * setting in `mailadmin_setting` (F3) and costs nothing later.
 */
final class ReservedLocalPart
{
    /**
     * @var list<string>
     */
    public const array NAMES = [
        'abuse',
        'hostmaster',
        'mailer-daemon',
        'postmaster',
        'root',
        'webmaster',
    ];

    private function __construct()
    {
    }

    public static function isReserved(LocalPart $localPart): bool
    {
        return in_array(strtolower($localPart->toString()), self::NAMES, true);
    }

    /**
     * Required by RFC 2142 — removing one of these leaves the domain out of
     * spec, which is a stronger statement than „conventionally reserved".
     */
    public static function isMandatory(LocalPart $localPart): bool
    {
        return in_array(strtolower($localPart->toString()), ['abuse', 'postmaster'], true);
    }
}

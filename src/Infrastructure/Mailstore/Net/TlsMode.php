<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Net;

/**
 * When TLS starts — not whether (M1, open point 1).
 *
 * The answer to the open point: **IMAPS on 993 is the default**, `STARTTLS` is
 * configurable. Implicit TLS is the shorter path and cannot be stripped by an
 * attacker on the wire; `STARTTLS` exists because ManageSieve has no implicit
 * port in Cyrus's default configuration and because a site may only expose 143.
 *
 * There is no third case. A `Plaintext` member would be the switch architecture
 * 9.2 and finding S-5 exist to remove — the legacy client spoke clear text on
 * 143 and sent the administrator password across it. Not offering the enum case
 * is cheaper to defend than offering it and guarding every use.
 */
enum TlsMode: string
{
    /** TLS from the first byte. IMAPS, port 993. */
    case Implicit = 'implicit';

    /**
     * Plaintext connection, immediately upgraded. The credential is written
     * only after {@see Transport::isEncrypted()} says yes.
     */
    case StartTls = 'starttls';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

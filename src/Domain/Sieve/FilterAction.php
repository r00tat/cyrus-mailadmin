<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

/**
 * What a filter rule does with a message that matches.
 */
enum FilterAction: string
{
    /** Needs the `fileinto` extension and a target folder. */
    case FileInto = 'fileinto';

    /** Throw the message away silently. */
    case Discard = 'discard';

    /** Keep it in the INBOX — useful to stop later rules from firing. */
    case Keep = 'keep';

    public function requiresFolder(): bool
    {
        return $this === self::FileInto;
    }

    /**
     * @return list<string> the Sieve extensions this action needs
     */
    public function requiredExtensions(): array
    {
        return $this === self::FileInto ? ['fileinto'] : [];
    }
}

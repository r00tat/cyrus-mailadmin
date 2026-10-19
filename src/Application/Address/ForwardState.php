<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Account\AccountRecord;
use Mailadmin\Domain\Model\NamingScheme;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use Mailadmin\Domain\Model\Virtual\Forward;
use Mailadmin\Domain\Model\Virtual\VirtualEntry;
use Mailadmin\Domain\Model\Virtual\VirtualEntryType;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * The forward of one account: the rows `alias = <user name>`, `username = ''`
 * (architecture 3.4).
 *
 * "Keep a copy" is not a field of the table. The legacy encoding — and the one
 * written here — is the account's own user name among the destinations
 * (`Forward::keepsLocalCopy()`, A3 open point 2, U-107). The representation
 * separates the two: `destinations` are where mail goes **besides** the
 * mailbox, `keep_copy` says whether the mailbox gets it too.
 */
final readonly class ForwardState
{
    public function __construct(
        public AccountRecord $account,
        public ClassifiedRows $rows,
    ) {
    }

    /**
     * @return list<Forward>
     */
    public function forwards(): array
    {
        return array_values(array_filter(
            $this->rows->entries,
            static fn (VirtualEntry $entry): bool => $entry instanceof Forward,
        ));
    }

    public function isConfigured(): bool
    {
        return $this->forwards() !== [];
    }

    /**
     * Where mail goes besides the mailbox.
     */
    public function destinations(): DestinationList
    {
        $own = $this->ownNames();
        $kept = [];

        foreach (AliasState::union($this->forwards())->all() as $destination) {
            if (!in_array(strtolower($destination), $own, true)) {
                $kept[] = $destination;
            }
        }

        return DestinationList::fromLegacyString(implode(DestinationList::SEPARATOR, $kept));
    }

    /**
     * Does the mailbox receive the mail as well? Always, when there is no
     * forward — and always in the `fqun` scheme, where the account's own
     * address row has the same key and Postfix joins both (U-107).
     */
    public function keepsCopy(): bool
    {
        if (!$this->isConfigured() || $this->copyRequired()) {
            return true;
        }

        foreach (AliasState::union($this->forwards())->all() as $destination) {
            if (in_array(strtolower($destination), $this->ownNames(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A forward without a copy cannot be honoured: the user name is the
     * address, so the address row matches the same key (U-107).
     */
    public function copyRequired(): bool
    {
        return $this->account->namingScheme() === NamingScheme::Fqun;
    }

    public function isEnabled(): bool
    {
        $forwards = $this->forwards();

        foreach ($forwards as $forward) {
            if (!$forward->isEnabled()) {
                return false;
            }
        }

        return $forwards !== [];
    }

    /**
     * Only forward rows under the key — an unknown row (one without a
     * destination, for instance) is never rewritten away (U-105).
     */
    public function isEditable(): bool
    {
        return $this->rows->allOfType(VirtualEntryType::Forward);
    }

    /**
     * @return list<VirtualRow>
     */
    public function forwardRows(): array
    {
        return array_map(static fn (Forward $forward): VirtualRow => $forward->row(), $this->forwards());
    }

    /**
     * The spellings that point back into this account: its stored user name
     * and its address, lower-cased.
     *
     * @return list<string>
     */
    public function ownNames(): array
    {
        $names = [strtolower($this->account->storedUsername)];
        $address = $this->account->identity?->address()->toString();

        if ($address !== null) {
            $names[] = strtolower($address);
        }

        return array_values(array_unique($names));
    }
}

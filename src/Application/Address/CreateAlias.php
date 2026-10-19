<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\AddressPolicy;

/**
 * `POST /domains/{domain}/aliases` — one new row in `virtual` (U-104, U-106).
 *
 * The address is checked against every spelling Postfix and Cyrus would
 * confuse it with — an existing row of any type, an account's user name in the
 * `fqun` scheme — under the namespace lock A1 and A2 take for the same
 * question, so that a domain rename or an account creation cannot slip in
 * between the check and the insert. Under the same lock, the new row is
 * expanded the way Postfix would: a destination that leads back to the alias
 * through other rows is refused (U-112).
 */
final readonly class CreateAlias
{
    public const string OPERATION = 'alias.create';

    public function __construct(
        private AddressStore $store,
        private AddressReader $reader,
        private DomainStore $domains,
        private DestinationRules $destinations,
        private SenderRules $senders,
        private RowWriter $writer,
        private LoopGuard $loops,
    ) {
    }

    /**
     * @throws AddressOperationFailed
     * @throws \Mailadmin\Domain\Exception\DomainError when the address or a
     *                                                 destination is malformed
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Domain\Policy\ResourceNotVisible
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     * @throws \Throwable                              when the audit entry cannot be written
     */
    public function handle(ActorContext $actor, DomainName $domain, AliasDraft $draft): AliasState
    {
        $address = (new EmailAddress(LocalPart::fromString($draft->localPart), $domain))->toLowerCase();
        AddressPolicy::requireWrite($actor, $address);

        $destinations = $this->destinations->parse($draft->destinations, [$address->toString()]);

        $this->domains->withNamespaceLock(function () use ($actor, $domain, $address, $destinations, $draft): void {
            if (!$this->domains->exists($domain)) {
                throw AddressOperationFailed::notFound('such domain');
            }

            if ($this->store->addressTaken($address->toString())) {
                throw AddressOperationFailed::taken($address->toString());
            }

            $sender = $draft->senderAccount !== null
                ? $this->senders->explicit($draft->senderAccount, $destinations)
                : ($this->senders->derived($destinations) ?? $this->senders->placeholder($domain));

            $row = new VirtualRow(
                $address->toString(),
                $destinations->toLegacyString(),
                $sender,
                $draft->enabled ? VirtualRow::STATUS_ENABLED : 0,
            );

            if ($row->isEnabled()) {
                $this->loops->requireNoLoop($address->toString(), [], [$row]);
            }

            $after = new AliasState($address, $this->reader->classify([$row]));

            $this->writer->replace(
                self::OPERATION,
                $address->toString(),
                [],
                [$row],
                AuditEntry::creation(
                    $actor,
                    self::OPERATION,
                    'alias',
                    $address->toString(),
                    after: AliasRecord::ofAlias($after),
                    domain: $domain,
                ),
            );
        });

        return new AliasState($address, $this->reader->withAlias($address->toString()));
    }
}

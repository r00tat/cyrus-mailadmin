<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Application\MailingList\ListDelivery;

/**
 * What `/maintenance/health` shows a superuser: the probes, and next to them
 * the list delivery settings (A6). The settings are not a probe — a transport
 * domain that is also a managed domain is a warning, it does not degrade
 * `status`.
 */
final readonly class HealthDetails
{
    public function __construct(
        public HealthReport $report,
        public ListDelivery $listDelivery,
        /** `null`: the database could not be asked. */
        public ?bool $transportDomainIsManaged,
    ) {
    }

    /**
     * `HealthDetails` in the contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->report->toDetailed() + [
            'list_delivery' => [
                'mode' => $this->listDelivery->mode->value,
                'transport_domain' => $this->listDelivery->transportDomain->toString(),
                'transport_domain_is_managed' => $this->transportDomainIsManaged,
            ],
        ];
    }
}

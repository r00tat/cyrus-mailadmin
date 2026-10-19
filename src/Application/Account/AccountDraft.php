<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\LocalPart;
use SensitiveParameter;

/**
 * What `POST /domains/{domain}/accounts` asks for, validated by the model.
 *
 * The local part is stored in lower case (U-77): Cyrus folds user names to
 * lower case at login (`username_tolower`), so `Anna` and `anna` are one
 * mailbox, and two rows that differ only in case would be one person with two
 * passwords.
 *
 * `$quota` is `null` when the request said nothing — the domain's default then
 * applies — and a {@see Quota} (possibly unlimited) when it did. The difference
 * between "absent" and "`null`" is the contract's (U-79).
 */
final readonly class AccountDraft
{
    public function __construct(
        public LocalPart $localPart,
        #[SensitiveParameter] public string $password,
        public ?Quota $quota,
        public AccountServices $services,
    ) {
    }

    /**
     * @param array<string, mixed> $body the request body, already validated against the contract
     *
     * @throws \Mailadmin\Domain\Exception\DomainError
     */
    public static function fromRequest(#[SensitiveParameter] array $body): self
    {
        /** @var mixed $localPart */
        $localPart = $body['local_part'] ?? '';
        /** @var mixed $password */
        $password = $body['password'] ?? '';

        $quota = null;

        if (array_key_exists('quota_kib', $body)) {
            /** @var mixed $limit */
            $limit = $body['quota_kib'];
            $quota = is_int($limit) ? Quota::ofKibibytes($limit) : Quota::unlimited();
        }

        /** @var mixed $services */
        $services = $body['services'] ?? [];

        return new self(
            LocalPart::fromString(strtolower(is_string($localPart) ? $localPart : '')),
            is_string($password) ? $password : '',
            $quota,
            self::servicesFrom(AccountServices::all(), is_array($services) ? $services : []),
        );
    }

    /**
     * Apply the flags a request carries to `$current`; absent ones stay.
     *
     * @param array<array-key, mixed> $flags
     */
    public static function servicesFrom(AccountServices $current, array $flags): AccountServices
    {
        $flag = static function (string $key, bool $default) use ($flags): bool {
            /** @var mixed $value */
            $value = $flags[$key] ?? null;

            return is_bool($value) ? $value : $default;
        };

        return new AccountServices(
            imap: $flag('imap', $current->imap),
            pop: $flag('pop', $current->pop),
            sieve: $flag('sieve', $current->sieve),
            smtpAuth: $flag('smtp_auth', $current->smtpAuth),
        );
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * Something that should name a mailbox, a domain or a destination does not.
 *
 * Note what is *not* here: a value that is merely unconventional. A local part
 * from the legacy data that carries characters we would not accept for a new
 * address is still a valid local part — it is flagged
 * (`LocalPart::isConventional()`), not rejected (U-22).
 */
final class InvalidAddress extends DomainError
{
    public static function emptyLocalPart(): self
    {
        return new self(
            DomainErrorCode::ADDRESS_INVALID_LOCAL_PART,
            'A local part must not be empty.',
        );
    }

    public static function localPartTooLong(int $length, int $limit): self
    {
        return new self(
            DomainErrorCode::ADDRESS_INVALID_LOCAL_PART,
            sprintf('A local part must not exceed %d bytes, got %d.', $limit, $length),
            ['length' => $length, 'limit' => $limit],
        );
    }

    public static function localPartHasForbiddenCharacter(string $localPart, string $reason): self
    {
        return new self(
            DomainErrorCode::ADDRESS_INVALID_LOCAL_PART,
            sprintf('Local part "%s" is not usable: %s.', self::excerpt($localPart), $reason),
            ['local_part' => self::excerpt($localPart), 'reason' => $reason],
        );
    }

    public static function unconventionalLocalPart(string $localPart): self
    {
        return new self(
            DomainErrorCode::ADDRESS_INVALID_LOCAL_PART,
            sprintf(
                'Local part "%s" uses characters that are not allowed for new addresses. '
                . 'Allowed: letters, digits and . - _ +, no leading, trailing or repeated dot.',
                self::excerpt($localPart),
            ),
            ['local_part' => self::excerpt($localPart)],
        );
    }

    public static function emptyDomainName(): self
    {
        return new self(
            DomainErrorCode::DOMAIN_INVALID_NAME,
            'A domain name must not be empty.',
        );
    }

    public static function domainNameTooLong(int $length, int $limit): self
    {
        return new self(
            DomainErrorCode::DOMAIN_INVALID_NAME,
            sprintf('A domain name must not exceed %d bytes, got %d.', $limit, $length),
            ['length' => $length, 'limit' => $limit],
        );
    }

    public static function domainNameHasForbiddenCharacter(string $domain, string $reason): self
    {
        return new self(
            DomainErrorCode::DOMAIN_INVALID_NAME,
            sprintf('Domain name "%s" is not usable: %s.', self::excerpt($domain), $reason),
            ['domain' => self::excerpt($domain), 'reason' => $reason],
        );
    }

    public static function unconventionalDomainName(string $domain): self
    {
        return new self(
            DomainErrorCode::DOMAIN_INVALID_NAME,
            sprintf(
                'Domain name "%s" is not a valid ASCII domain. Internationalised names are '
                . 'stored as punycode (xn--); convert before handing the value to the model.',
                self::excerpt($domain),
            ),
            ['domain' => self::excerpt($domain)],
        );
    }

    public static function notAnAddress(string $value): self
    {
        return new self(
            DomainErrorCode::ADDRESS_INVALID,
            sprintf('"%s" is not a mail address: no "@" separator.', self::excerpt($value)),
            ['address' => self::excerpt($value)],
        );
    }

    public static function emptyDestinationList(): self
    {
        return new self(
            DomainErrorCode::ADDRESS_INVALID_DESTINATION,
            'A destination list must name at least one destination.',
        );
    }

    public static function unusableDestination(string $destination): self
    {
        return new self(
            DomainErrorCode::ADDRESS_INVALID_DESTINATION,
            sprintf('Destination "%s" is not usable.', self::excerpt($destination)),
            ['destination' => self::excerpt($destination)],
        );
    }
}

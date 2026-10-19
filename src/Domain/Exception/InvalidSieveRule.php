<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * A Sieve rule that could not be rendered into a valid script.
 *
 * The model refuses the rule instead of rendering something questionable —
 * an invalid script is rejected by the server at `PUTSCRIPT` time, far away
 * from the input that caused it.
 */
final class InvalidSieveRule extends DomainError
{
    public static function emptyVacationMessage(): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            'A vacation rule needs a message body.',
        );
    }

    public static function vacationIntervalOutOfRange(int $days, int $max): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            sprintf('A vacation interval must be between 1 and %d days, got %d.', $max, $days),
            ['days' => $days, 'max' => $max],
        );
    }

    /**
     * The subject holds a control character — a line break would end up in
     * the header of the auto-reply. The value itself stays out of the message.
     */
    public static function unusableVacationSubject(): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            'A vacation subject must not contain control characters such as line breaks.',
        );
    }

    public static function emptyConditionSet(): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            'A filter rule needs at least one condition.',
        );
    }

    public static function emptyHeaderName(): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            'A filter condition needs a header name.',
        );
    }

    public static function unusableHeaderName(string $header): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            sprintf('"%s" is not a usable header name.', self::excerpt($header)),
            ['header' => self::excerpt($header)],
        );
    }

    public static function emptyConditionValue(string $header): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            sprintf('The condition on header "%s" needs a value.', self::excerpt($header)),
            ['header' => self::excerpt($header)],
        );
    }

    public static function folderRequired(): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            'A fileinto action needs a target folder.',
        );
    }

    public static function folderNotAllowed(string $action): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            sprintf('The action "%s" takes no target folder.', self::excerpt($action, 16)),
            ['action' => self::excerpt($action, 16)],
        );
    }

    public static function unusableFolder(string $folder): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            sprintf('"%s" is not a usable folder name.', self::excerpt($folder)),
            ['folder' => self::excerpt($folder)],
        );
    }

    public static function emptyScriptName(): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            'A Sieve script needs a name.',
        );
    }

    public static function unusableScriptName(string $name): self
    {
        return new self(
            DomainErrorCode::SIEVE_INVALID_RULE,
            sprintf('"%s" is not a usable Sieve script name.', self::excerpt($name)),
            ['name' => self::excerpt($name)],
        );
    }
}

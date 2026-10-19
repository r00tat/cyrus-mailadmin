<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

use InvalidArgumentException;

/**
 * An audit entry that could not be built from what a call site handed over.
 *
 * Always a programming error — an action name that does not follow
 * `<object>.<verb>`, an object type that is not a plain identifier. It is thrown
 * rather than swallowed on purpose: an entry with a nonsense action is a row
 * that looks like a record and is not one, and the whole value of this table is
 * that a search for `domain.delete` finds every deletion.
 *
 * It is **not** used for a failing write. A database that cannot take the row
 * raises `PersistenceError`, and what happens then is U-63's business, not this
 * class's.
 */
final class InvalidAuditEntry extends InvalidArgumentException
{
    public static function malformedAction(string $action): self
    {
        return new self(sprintf(
            'The audit action "%s" is not of the form <object>.<verb>, both parts snake_case '
            . '(for example "domain.create"). It names what happened and is searched for '
            . 'literally, so it may not be free text.',
            $action,
        ));
    }

    public static function actionTooLong(string $action, int $limit): self
    {
        return new self(sprintf(
            'The audit action "%s" is longer than the %d characters the column holds.',
            $action,
            $limit,
        ));
    }

    public static function malformedObjectType(string $type): self
    {
        return new self(sprintf(
            'The audit object type "%s" is not a lower-case identifier. It groups entries by the '
            . 'kind of thing they are about ("domain", "account", "session"), so it comes from the '
            . 'code and never from a request.',
            $type,
        ));
    }

    public static function emptyActor(): self
    {
        return new self(
            'An audit entry needs an actor. Where no administrator is authenticated — a sign-in '
            . 'attempt, a command-line run — use AuditActor::named() or AuditActor::system(), '
            . 'which say so explicitly.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

/**
 * The error codes A5's use cases raise (U-7).
 *
 * As everywhere, the authoritative list is the `ErrorCode` enum in
 * `openapi/components/common.yaml`; these constants exist so that no layer
 * spells a code as a literal.
 *
 * Two of the three carry the `request` prefix and are borrowed from
 * {@see \Mailadmin\Http\Problem\ErrorCode} rather than invented, and in one case
 * that is a decision rather than convenience — see {@see NOT_FOUND}.
 */
final class MailingListErrorCode
{
    /** A list of that name is already in the table. The name is the primary key. */
    public const string ALREADY_EXISTS = 'list.already_exists';

    /**
     * No such list — **the same code the framework produces for an object the
     * actor may not see.**
     *
     * A `list.not_found` of its own would read better and would be a mistake.
     * `lists` has no domain column, so if list management is ever widened to
     * domain masters (the two-line change {@see \Mailadmin\Domain\Policy\ListPolicy}
     * describes), a list outside the actor's scope answers `404` through
     * `ResourceNotVisible` and carries `request.not_found`. A distinct code for
     * "really absent" would then be exactly the distinguisher that CLAUDE.md's
     * 403/404 rule exists to remove — a client could enumerate the lists it may
     * not see by reading the `code` field.
     */
    public const string NOT_FOUND = 'request.not_found';

    /** The `If-Match` value does not match the current representation (U-19). */
    public const string PRECONDITION_FAILED = 'request.precondition_failed';

    private function __construct()
    {
    }
}

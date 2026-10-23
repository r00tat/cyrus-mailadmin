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
 * Two of them carry the `request` prefix and are borrowed from
 * {@see \Mailadmin\Http\Problem\ErrorCode} rather than invented, and in one case
 * that is a decision rather than convenience — see {@see NOT_FOUND}.
 */
final class MailingListErrorCode
{
    /**
     * The address is in use: as a list address, as any row in `virtual`, as an
     * account's user name, or through a list with the same list key
     * (`a.b@c` and `a@b.c` — spec 4.3, "Identität").
     */
    public const string ADDRESS_TAKEN = 'list.address_taken';

    /**
     * The local part carries the recipient delimiter `+`. The transport hands
     * the pipe script the local part without its extension, so such a list
     * could never be found (plan B5, step 0).
     */
    public const string ADDRESS_HAS_EXTENSION = 'list.address_has_extension';

    /**
     * The domain of the list address is not a domain this server manages — a
     * row in `domain`. Same shape as `admin.unknown_domain`.
     */
    public const string UNKNOWN_DOMAIN = 'list.unknown_domain';

    /**
     * No such list — **the same code the framework produces for an object the
     * actor may not see.**
     *
     * A `list.not_found` of its own would read better and would be a mistake.
     * A list outside a domain master's scope answers `404` through
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
